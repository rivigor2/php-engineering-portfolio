<?php

declare(strict_types=1);

/**
 * Public clean-room excerpt of the project session start gate.
 *
 * Public implementation of start logging, a small plan grammar and contract
 * validation. It does not merge plans, sandbox a runtime or prove that a
 * previous done step was unchanged: those are separate process responsibilities.
 */
final class PlanSnapshot
{
    /**
     * @param array<string, scalar|null> $header
     * @param list<int> $summarySteps
     * @param list<int> $detailSteps
     */
    public function __construct(
        public readonly array $header,
        public readonly array $summarySteps,
        public readonly array $detailSteps,
    ) {
    }
}

final class PlanCoherenceResult implements JsonSerializable
{
    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly bool $coherent,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly PlanSnapshot $snapshot,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'coherent' => $this->coherent,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'header' => $this->snapshot->header,
            'summary_steps' => $this->snapshot->summarySteps,
            'detail_steps' => $this->snapshot->detailSteps,
        ];
    }
}

final class PlanCoherenceValidator
{
    public function validateFile(string $planPath): PlanCoherenceResult
    {
        if (!is_file($planPath)) {
            throw new RuntimeException('The root IMPLEMENTATION_PLAN.md is missing');
        }

        $content = file_get_contents($planPath);
        if ($content === false) {
            throw new RuntimeException('Cannot read implementation plan');
        }

        $snapshot = new PlanSnapshot(
            $this->parseHeader($content),
            $this->parseSummarySteps($content),
            $this->parseDetailSteps($content),
        );

        $errors = [];
        $warnings = [];
        $total = (int) ($snapshot->header['total_steps'] ?? 0);
        $current = $snapshot->header['current_step'] ?? null;
        $done = (int) ($snapshot->header['steps_done'] ?? 0);

        if ($total < 1) {
            $errors[] = 'header.total_steps must be positive';
        }
        if (!array_key_exists('steps_done', $snapshot->header) || $done < 0 || $done > $total) {
            $errors[] = 'header.steps_done must be between zero and total_steps';
        }
        if (!array_key_exists('current_step', $snapshot->header)
            || ($current !== null && (!is_int($current) || $current < 1 || $current > $total))
        ) {
            $errors[] = 'header.current_step must be null or an existing step';
        }

        $expected = $total > 0 ? range(1, $total) : [];
        $missingSummary = array_values(
            array_diff($expected, $snapshot->summarySteps),
        );
        $missingDetails = array_values(
            array_diff($expected, $snapshot->detailSteps),
        );

        if ($missingSummary !== []) {
            $errors[] = 'summary is missing steps: ' . implode(', ', $missingSummary);
        }
        if ($missingDetails !== []) {
            $errors[] = 'details are missing steps: ' . implode(', ', $missingDetails);
        }

        $summaryMax = $snapshot->summarySteps === []
            ? 0
            : max($snapshot->summarySteps);
        $detailMax = $snapshot->detailSteps === []
            ? 0
            : max($snapshot->detailSteps);

        if ($summaryMax !== $detailMax) {
            $errors[] = "summary max {$summaryMax} differs from detail max {$detailMax}";
        }
        if ($summaryMax > $total || $detailMax > $total) {
            $errors[] = 'plan contains steps beyond header.total_steps';
        }

        // Check states, not just matching step numbers. A structurally complete
        // table can still lie about current/done and resume the wrong task.
        $summaryStates = [];
        $detailStates = [];
        preg_match_all('/^\|\s*(\d+)\s*\|[^\r\n]*?\|\s*\[([a-z_]+)\]\s*\|/m', $content, $rows, PREG_SET_ORDER);
        preg_match_all('/^###\s+Шаг\s+(\d+)\s+\[([a-z_]+)\]/mu', $content, $blocks, PREG_SET_ORDER);
        foreach ([['rows' => $rows, 'layer' => 'summary'], ['rows' => $blocks, 'layer' => 'detail']] as $group) {
            $states = [];
            foreach ($group['rows'] as $row) {
                $number = (int) $row[1];
                if (isset($states[$number])) {
                    $errors[] = $group['layer'] . ": duplicate step {$number}";
                }
                $states[$number] = $row[2];
            }
            if ($group['layer'] === 'summary') {
                $summaryStates = $states;
            } else {
                $detailStates = $states;
            }
        }
        foreach ($expected as $number) {
            $state = $summaryStates[$number] ?? null;
            if (!in_array($state, ['todo', 'current', 'in_review', 'blocked', 'done'], true)
                || $state !== ($detailStates[$number] ?? null)
            ) {
                $errors[] = "step {$number}: missing, invalid or mismatched state";
            }
        }
        if (count(array_filter($summaryStates, fn ($s) => $s === 'done')) !== $done) {
            $errors[] = 'header.steps_done disagrees with the progress table';
        }
        $currentSteps = array_keys(array_filter($summaryStates, fn ($s) => $s === 'current'));
        if ($currentSteps !== ($current === null ? [] : [$current])) {
            $errors[] = 'header.current_step disagrees with the progress table';
        }

        return new PlanCoherenceResult(
            $errors === [],
            $errors,
            $warnings,
            $snapshot,
        );
    }

    /** @return array<string, scalar|null> */
    private function parseHeader(string $content): array
    {
        if (!preg_match('/\A---\R(.*?)\R---\R/s', $content, $match)) {
            return [];
        }

        $header = [];
        foreach (preg_split('/\R/', $match[1]) ?: [] as $line) {
            if (!preg_match('/^([a-z_]+):\s*(.*?)\s*$/', $line, $parts)) {
                continue;
            }
            $value = $parts[2];
            $header[$parts[1]] = match (true) {
                $value === 'null' => null,
                $value === 'true' => true,
                $value === 'false' => false,
                ctype_digit($value) => (int) $value,
                default => trim($value, "\"'"),
            };
        }
        return $header;
    }

    /** @return list<int> */
    private function parseSummarySteps(string $content): array
    {
        preg_match_all('/^\|\s*(\d+)\s*\|/m', $content, $matches);
        return $this->uniqueSortedIntegers($matches[1] ?? []);
    }

    /** @return list<int> */
    private function parseDetailSteps(string $content): array
    {
        preg_match_all('/^###\s+Шаг\s+(\d+)\b/mu', $content, $matches);
        return $this->uniqueSortedIntegers($matches[1] ?? []);
    }

    /**
     * @param list<string> $values
     * @return list<int>
     */
    private function uniqueSortedIntegers(array $values): array
    {
        $numbers = array_values(
            array_unique(array_map('intval', $values)),
        );
        sort($numbers);
        return $numbers;
    }
}

final class HandoffGuard
{
    /** Contract check only; immutability requires comparing the old/new plan.
     * @param array<string, mixed> $handoff
     */
    public function assertMergeable(array $handoff): void
    {
        if (($handoff['must_merge_into_implementation_plan'] ?? false) !== true) {
            throw new DomainException(
                'Handoff is sidecar data and must be merged into the root plan',
            );
        }
        if (($handoff['done_steps_are_immutable'] ?? false) !== true) {
            throw new DomainException('Patch cannot rewrite completed steps');
        }

        $criteria = $handoff['acceptance'] ?? null;
        if (!is_array($criteria) || $criteria === []) {
            throw new DomainException('Handoff needs acceptance criteria');
        }

        $ids = [];
        foreach ($criteria as $criterion) {
            if (!is_array($criterion)) {
                throw new DomainException('Invalid acceptance criterion');
            }
            $id = (string) ($criterion['id'] ?? '');
            $verify = (string) ($criterion['verify'] ?? '');
            if ($id === '' || $verify === '') {
                throw new DomainException(
                    'Every acceptance criterion needs id and verify',
                );
            }
            if (isset($ids[$id])) {
                throw new DomainException("Duplicate acceptance id: {$id}");
            }
            $ids[$id] = true;
        }
    }
}

final class SessionGate
{
    public function __construct(
        private readonly PlanCoherenceValidator $plans,
        private readonly HandoffGuard $handoffs,
    ) {
    }

    /**
     * The chronology append is deliberately first. If it cannot be persisted,
     * the session must not continue to plan or code changes.
     *
     * @param array<string, mixed>|null $handoff
     */
    public function open(
        string $projectDir,
        string $issueIdentifier,
        ?array $handoff = null,
    ): PlanCoherenceResult {
        // Trusted caller supplies an already approved project, not a chat path.
        $resolved = realpath($projectDir);
        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('Approved project directory does not exist');
        }
        $projectDir = $resolved;
        $chronology = $projectDir . '/logs/chronology.md';
        $plan = $projectDir . '/IMPLEMENTATION_PLAN.md';

        $this->appendChronology(
            $chronology,
            sprintf(
                "\n## Session start · %s · %s\n- Gate opened before plan/code changes.\n",
                $issueIdentifier,
                (new DateTimeImmutable())->format(DATE_ATOM),
            ),
        );

        if ($handoff !== null) {
            $this->handoffs->assertMergeable($handoff);
            // The public excerpt stops at the guard. Production phase 0 adds
            // new numbered steps to the root plan and validates it again.
        }

        $result = $this->plans->validateFile($plan);
        if (!$result->coherent) {
            throw new DomainException(
                'Plan layers are not coherent: ' . implode('; ', $result->errors),
            );
        }
        return $result;
    }

    private function appendChronology(string $path, string $entry): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            throw new RuntimeException('Cannot create chronology directory');
        }

        $stream = fopen($path, 'ab');
        if ($stream === false) {
            throw new RuntimeException('Cannot open chronology');
        }

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new RuntimeException('Cannot lock chronology');
            }
            if (fwrite($stream, $entry) !== strlen($entry)) {
                throw new RuntimeException('Cannot append chronology');
            }
            if (!fflush($stream)) {
                throw new RuntimeException('Cannot flush chronology');
            }
            flock($stream, LOCK_UN);
        } finally {
            fclose($stream);
        }

        if (!str_contains(
            (string) file_get_contents($path),
            trim($entry),
        )) {
            throw new RuntimeException('Chronology append verification failed');
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $plan = $argv[1] ?? null;
    if ($plan === null) {
        fwrite(STDERR, "Usage: php session_gate.php IMPLEMENTATION_PLAN.md\n");
        exit(64);
    }

    try {
        $result = (new PlanCoherenceValidator())->validateFile($plan);
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        exit($result->coherent ? 0 : 2);
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
