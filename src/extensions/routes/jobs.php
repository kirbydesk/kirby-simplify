<?php

/**
 * Job Routes for Kirby Simplify Plugin
 *
 * Routes for managing background translation jobs and job queue.
 * Handles: simplify/job/*, simplify/jobs
 */

use chrfickinger\Simplify\Helpers\RouteHelper;
use chrfickinger\Simplify\Queue\TranslationQueue;
use chrfickinger\Simplify\Logging\StatsLogger;
use chrfickinger\Simplify\Config\ConfigHelper;

return [
    [
        "pattern" => "simplify/jobs",
        "method" => "GET",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $variantCode = $context['kirby']->request()->get('variantCode');
            $status = $context['kirby']->request()->get('status');

            $validation = RouteHelper::validateRequired(
                ['variantCode' => $variantCode],
                ['variantCode']
            );
            if (!$validation['success']) {
                return $validation['error'];
            }

            return RouteHelper::handleAction(function() use ($variantCode, $status) {
                $queue = new TranslationQueue();

                // Check for stuck jobs and mark them as timeout (10 minute timeout)
                $queue->resetStuckJobs(10);

                if ($status) {
                    $jobs = $queue->getJobsByStatus($status);
                    // Filter by variant
                    $jobs = array_filter($jobs, fn($job) => $job['variantCode'] === $variantCode);
                } else {
                    $jobs = $queue->getJobsForVariant($variantCode);
                }

                return RouteHelper::successResponse('', ['jobs' => array_values($jobs)]);
            }, $context['logger']);
        },
    ],
    [
        "pattern" => "simplify/job/cancel",
        "method" => "POST",
        "action" => function () {
            $auth = RouteHelper::requireAuth();
            if (!$auth['success']) {
                return $auth['error'];
            }

            $context = RouteHelper::getContext();
            $data = $context['kirby']->request()->data();

            $validation = RouteHelper::validateRequired($data, ['jobId']);
            if (!$validation['success']) {
                return $validation['error'];
            }

            return RouteHelper::handleAction(function() use ($data, $context) {
                $logger = $context['logger'];
                $queue = new TranslationQueue();
                $job = $queue->getJob($data['jobId']);

                if (!$job) {
                    return RouteHelper::errorResponse('Job not found');
                }

                // Log cancellation to SQLite
                $statsLogger = new StatsLogger();
                $variantConfig = ConfigHelper::getVariantConfig($job['variantCode']);
                $modelName = $variantConfig['provider'] ?? '';

                // Find provider ID from model
                $mainConfig = ConfigHelper::getConfig();
                $providerId = '';
                if (isset($mainConfig['providers'])) {
                    foreach ($mainConfig['providers'] as $id => $providerConfig) {
                        if (($providerConfig['model'] ?? '') === $modelName) {
                            $providerId = $id;
                            break;
                        }
                    }
                }
                if (empty($providerId)) {
                    $providerId = $modelName;
                }

                // Get page for UUID
                $kirby = $context['kirby'];
                $page = $kirby->page($job['pageId']);
                $pageUuid = $page && $page->uuid() ? $page->uuid()->toString() : null;

                // Determine action based on isManual flag in job
                $isManual = $job['isManual'] ?? true; // Default to manual for backward compatibility
                $action = $isManual ? 'manual' : 'auto';
                $statsLogger->logTranslation([
                    'pageId' => $job['pageId'],
                    'pageUuid' => $pageUuid,
                    'pageTitle' => $job['pageTitle'],
                    'languageCode' => $job['variantCode'],
                    'providerId' => $providerId,
                    'model' => $modelName,
                    'action' => $action,
                    'strategy' => $job['strategy'] ?? 'full',
                    'status' => 'CANCELED',
                    'success' => false,
                    'error' => 'Job canceled by user',
                    'fieldsTranslated' => 0,
                    'inputTokens' => 0,
                    'outputTokens' => 0,
                    'cost' => 0,
                ]);

                // Delete job file
                $queue->deleteJob($data['jobId']);

                if ($logger) {
                    $logger->info("Job {$data['jobId']} cancelled by user");
                }

                // Check if there are pending jobs and start worker if needed
                $pendingJobs = $queue->getPendingJobs();
                if (!empty($pendingJobs)) {
                    $queue->startWorker();
                    if ($logger) {
                        $logger->info("Started worker for " . count($pendingJobs) . " pending job(s)");
                    }
                }

                return RouteHelper::successResponse('Job cancelled successfully');
            }, $context['logger']);
        },
    ],
];
