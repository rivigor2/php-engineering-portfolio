<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Portfolio\\Commerce\\' => __DIR__ . '/current-experience/ecommerce-marketplace/src/',
        'Portfolio\\DocumentFlow\\' => __DIR__ . '/current-experience/b2b-document-flow/src/',
        'Portfolio\\CrmWorkflow\\' => __DIR__ . '/current-experience/crm-workflow-backend/src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = $directory . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }

        return;
    }
});