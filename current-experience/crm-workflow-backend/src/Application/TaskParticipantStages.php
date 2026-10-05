<?php

declare(strict_types=1);

namespace Portfolio\CrmWorkflow\Application;

use InvalidArgumentException;

/** A synthetic projection of per-participant Scrum stages in a task card. */
final class TaskParticipantStages
{
    /**
     * @param list<string> $currentParticipantIds Authoritative current task participants.
     * @param list<array{participant_id: string, stage: string}> $savedStages May contain former participants.
     * @return list<array{participant_id: string, stage: string}>
     */
    public function visible(array $currentParticipantIds, array $savedStages): array
    {
        $current = $this->normalizeParticipants($currentParticipantIds);
        $stages = [];
        foreach ($savedStages as $row) {
            $id = $row['participant_id'];
            if (!isset($current[$id])) {
                continue;
            }
            if (isset($stages[$id])) {
                throw new InvalidArgumentException('Conflicting participant stage records require reconciliation.');
            }
            $stages[$id] = $row['stage'];
        }
        $result = [];
        foreach (array_keys($current) as $id) {
            $result[] = ['participant_id' => (string) $id, 'stage' => $stages[$id] ?? 'not_assigned'];
        }
        return $result;
    }

    /**
     * @param list<string> $currentParticipantIds
     * @param list<array{participant_id: string, stage: string}> $savedStages
     * @param list<string> $allowedStageIds Loaded from the target Scrum board.
     * @return list<array{participant_id: string, stage: string}>
     */
    public function change(
        array $currentParticipantIds,
        array $savedStages,
        string $participantId,
        string $targetStage,
        array $allowedStageIds,
        bool $actorCanEditTask,
    ): array {
        if (!$actorCanEditTask) {
            throw new \DomainException('Task edit permission is required.', 403);
        }
        $current = $this->normalizeParticipants($currentParticipantIds);
        if (!isset($current[$participantId])) {
            throw new \DomainException('Former participant stage must not be changed from this task.');
        }
        if (!in_array($targetStage, $allowedStageIds, true)) {
            throw new \DomainException('Stage does not belong to the selected Scrum board.');
        }
        // Validate the projection; preserve former participants in storage.
        $this->visible($currentParticipantIds, $savedStages);
        $rows = $savedStages;
        $updated = false;
        foreach ($rows as &$row) {
            if ($row['participant_id'] === $participantId) {
                $row['stage'] = $targetStage;
                $updated = true;
            }
        }
        unset($row);
        if (!$updated) {
            $rows[] = ['participant_id' => $participantId, 'stage' => $targetStage];
        }
        return $rows;
    }

    /** @param list<string> $ids @return array<string, true> */
    private function normalizeParticipants(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            if (trim($id) === '') {
                throw new InvalidArgumentException('Participant identity cannot be empty.');
            }
            $result[$id] = true;
        }
        return $result;
    }
}
