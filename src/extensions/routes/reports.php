<?php

/**
 * Report Routes for Kirby Simplify Plugin
 *
 * Routes for managing translation reports and logs.
 * Handles: simplify/reports/*
 */

use kirbydesk\Simplify\Helpers\RouteHelper;
use kirbydesk\Simplify\Logging\StatsLogger;
use kirbydesk\Simplify\Logging\ReportsLogger;
use kirbydesk\Simplify\Queue\TranslationQueue;

return [
    [
        "pattern" => "simplify/reports/counts",
        "method" => "GET",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $variantCode = $context['kirby']->request()->get('variantCode');

            $validation = RouteHelper::validateRequired(
                ['variantCode' => $variantCode],
                ['variantCode']
            );
            if (!$validation['success']) {
                return $validation['error'];
            }

            return RouteHelper::handleAction(function() use ($variantCode, $context) {
                $kirby = $context['kirby'];

                // Count pending jobs in queue
                $queue = new TranslationQueue();
                $jobs = $queue->getJobsForVariant($variantCode);
                $queueCount = count(array_filter($jobs, fn($j) => $j['status'] === 'pending'));

                // Count reports
                $reportsLogger = new ReportsLogger();
                $reports = $reportsLogger->getReports($variantCode);
                $reportsCount = count($reports);

                // Count cache entries
                $cacheCount = 0;
                $cacheDb = $kirby->root('logs') . '/simplify/db/translation-cache.sqlite';
                if (file_exists($cacheDb)) {
                    try {
                        $db = new \PDO('sqlite:' . $cacheDb);
                        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                        $stmt = $db->prepare('SELECT COUNT(*) FROM translation_cache WHERE language_code = ?');
                        $stmt->execute([$variantCode]);
                        $cacheCount = (int) $stmt->fetchColumn();
                    } catch (\Exception $e) {
                        // Ignore cache count errors
                    }
                }

                return RouteHelper::successResponse('', [
                    'queueCount' => $queueCount,
                    'reportsCount' => $reportsCount,
                    'cacheCount' => $cacheCount
                ]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/reports",
        "method" => "GET",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $variantCode = $context['kirby']->request()->get('variantCode');

            $validation = RouteHelper::validateRequired(
                ['variantCode' => $variantCode],
                ['variantCode']
            );
            if (!$validation['success']) {
                return $validation['error'];
            }

            return RouteHelper::handleAction(function() use ($variantCode) {
                $reportsLogger = new ReportsLogger();
                $reports = $reportsLogger->getReports($variantCode);

                // Transform SQLite data to match frontend format
                $formattedReports = array_map(function($row) {
                    return [
                        'timestamp' => date('Y-m-d H:i:s', $row['timestamp']),
                        'timestampRaw' => $row['timestamp'], // Add raw timestamp for delete operations
                        'action' => strtoupper($row['action'] ?? 'MANUAL'),
                        'status' => $row['status'] ?? 'UNKNOWN',
                        'pageId' => $row['page_id'],
                        'pageUuid' => $row['page_uuid'],
                        'pageTitle' => $row['page_title'],
                        'tokens' => ($row['input_tokens'] ?? 0) + ($row['output_tokens'] ?? 0),
                        'cost' => $row['cost'] ?? 0,
                        'error' => $row['error'],
                        'strategy' => $row['strategy'],
                        'fieldsTranslated' => $row['fields_translated'] ?? 0,
                        'raw' => json_encode($row)
                    ];
                }, $reports);

                return RouteHelper::successResponse('', [
                    'reports' => $formattedReports
                ]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/reports/clear",
        "method" => "POST",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();

            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            return RouteHelper::handleAction(function() use ($data, $context) {
                $variantCode = $data['variantCode'];
                $kirby = $context['kirby'];

                // Clear reports from SQLite
                $reportsLogger = new ReportsLogger();
                $reportsLogger->clearReports($variantCode);

                // Clear all jobs for this variant
                $queue = new TranslationQueue();
                $jobs = $queue->getJobsForVariant($variantCode);

                foreach ($jobs as $job) {
                    $queue->deleteJob($job['id']);
                }

                // Clear worker logs for this variant only
                $variantLogDir = $kirby->root('logs') . '/simplify/' . $variantCode;

                if (is_dir($variantLogDir)) {
                    $workerLogs = glob($variantLogDir . '/worker-*.log');

                    foreach ($workerLogs as $logFile) {
                        @unlink($logFile);
                    }

                    // Remove empty directory
                    @rmdir($variantLogDir);
                }

                return RouteHelper::successResponse('Reports, jobs and logs cleared successfully');
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/reports/delete",
        "method" => "POST",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();

            $validation = RouteHelper::validateRequired($data, ['variantCode', 'timestamp']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            return RouteHelper::handleAction(function() use ($data) {
                $reportsLogger = new ReportsLogger();
                $deleted = $reportsLogger->deleteReportByTimestamp($data['variantCode'], (int)$data['timestamp']);

                if ($deleted) {
                    return RouteHelper::successResponse('Report entry deleted successfully');
                } else {
                    return RouteHelper::errorResponse('Failed to delete report entry');
                }
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/cache/clear",
        "method" => "POST",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();

            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            return RouteHelper::handleAction(function() use ($data, $context) {
                $variantCode = $data['variantCode'];
                $kirby = $context['kirby'];

                // Clear translation cache for this variant
                $cacheDb = $kirby->root('logs') . '/simplify/db/translation-cache.sqlite';

                if (file_exists($cacheDb)) {
                    try {
                        $db = new \PDO('sqlite:' . $cacheDb);
                        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                        // Delete all cache entries for this language variant
                        $stmt = $db->prepare('DELETE FROM translation_cache WHERE language_code = ?');
                        $stmt->execute([$variantCode]);

                        $count = $stmt->rowCount();

                        return RouteHelper::successResponse("Cache cleared successfully ($count entries deleted)");
                    } catch (\Exception $e) {
                        return RouteHelper::errorResponse('Failed to clear cache: ' . $e->getMessage());
                    }
                } else {
                    return RouteHelper::successResponse('Cache is already empty (no cache file found)');
                }
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/queue/clear",
        "method" => "POST",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();

            $validation = RouteHelper::validateRequired($data, ['variantCode']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            return RouteHelper::handleAction(function() use ($data) {
                $variantCode = $data['variantCode'];

                // Clear only pending jobs for this variant (not processing ones)
                $queue = new TranslationQueue();
                $jobs = $queue->getJobsForVariant($variantCode);

                $count = 0;
                foreach ($jobs as $job) {
                    // Only delete pending jobs, leave processing jobs running
                    if ($job['status'] === 'pending') {
                        $queue->deleteJob($job['id']);
                        $count++;
                    }
                }

                return RouteHelper::successResponse("Queue cleared successfully ($count pending jobs deleted)");
            }, $context['logger']);
        },
    ],
];
