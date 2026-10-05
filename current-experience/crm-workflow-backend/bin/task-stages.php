<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/autoload.php';
use Portfolio\CrmWorkflow\Application\ChangeTaskParticipantStage;
use Portfolio\CrmWorkflow\Application\TaskParticipantStages;
use Portfolio\CrmWorkflow\Infrastructure\SQLite\PdoTaskStageGateway;

try {
    $input = $argv[1] ?? __DIR__ . '/../examples/task-participant-stages.json';
    $raw = file_get_contents($input);
    if ($raw === false) {
        throw new RuntimeException('Cannot read synthetic scenario.');
    }
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    // In-memory SQLite is real storage, isolated from the portal. CLI fixtures supply session actors.
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $policy = new TaskParticipantStages();
    $gateway = new PdoTaskStageGateway($pdo, $policy);
    $task = $data['server_task'];
    $seed = $pdo->prepare('INSERT INTO task_stage_context VALUES (
        :task, :board, :version, :participants, :stages, :editors, :saved
    )');
    $seed->execute([
        'task' => $task['task_id'], 'board' => $task['board_id'], 'version' => $task['version'],
        'participants' => json_encode($task['current_participant_ids'], JSON_THROW_ON_ERROR),
        'stages' => json_encode($task['allowed_stage_ids'], JSON_THROW_ON_ERROR),
        'editors' => json_encode($task['editor_ids'], JSON_THROW_ON_ERROR),
        'saved' => json_encode($task['saved_stages'], JSON_THROW_ON_ERROR),
    ]);
    $service = new ChangeTaskParticipantStage($gateway, $policy);
    foreach ($data['requests'] as $request) {
        try {
            $snapshot = $service->handle(
                $task['task_id'], $request['session_actor_id'], $request['expected_version'],
                $request['participant_id'], $request['target_stage'],
            );
            $output = [
                'result' => 'saved', 'version' => $snapshot->version,
                'visible' => $policy->visible($snapshot->participantIds, $snapshot->savedStages),
                'stored_with_history' => $snapshot->savedStages,
            ];
        } catch (DomainException $error) {
            $output = ['result' => 'refused', 'reason' => $error->getMessage()];
        }
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
