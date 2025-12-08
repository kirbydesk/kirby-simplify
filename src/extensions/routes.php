<?php

/**
 * API Routes for Kirby Simplify Plugin
 *
 * This file loads and merges all API routes from the routes/ subdirectory.
 * Routes are organized by functionality for better maintainability.
 */

return array_merge(
    require __DIR__ . '/routes/languages.php',   // Language management routes
    require __DIR__ . '/routes/variants.php',    // Variant configuration routes
    require __DIR__ . '/routes/providers.php',   // Provider management routes
    require __DIR__ . '/routes/models.php',      // Model management routes
    require __DIR__ . '/routes/pages.php',       // Page translation routes
    require __DIR__ . '/routes/reports.php',     // Report and logging routes
    require __DIR__ . '/routes/jobs.php',        // Background job routes
    require __DIR__ . '/routes/meta.php',        // Metadata routes (templates, field types)
    require __DIR__ . '/routes/project.php',     // Project configuration routes
    require __DIR__ . '/routes/system.php'       // System diagnostics routes
);
