<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Technopath Commerce Intelligence Engine Configuration
    |--------------------------------------------------------------------------
    |
    | Central configuration for the intelligence engine, change detection
    | thresholds, friction score weights, and AI analyst settings.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Finding Categories
    |--------------------------------------------------------------------------
    */
    'finding_categories' => [
        'Revenue',
        'Conversion',
        'Behavioral',
        'Search',
        'Checkout',
        'Customer',
        'Technical',
        'Merchandising',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployment Types
    |--------------------------------------------------------------------------
    */
    'deployment_types' => [
        'theme'            => 'Theme Update',
        'platform_release' => 'Platform Release',
        'checkout'         => 'Checkout Change',
        'search'           => 'Search Configuration',
        'app_install'      => 'App Installation',
        'promotion'        => 'Promotion / Campaign',
        'configuration'    => 'Configuration Change',
        'other'            => 'Other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Change Detection Thresholds
    |--------------------------------------------------------------------------
    | Percentage change (as decimal) required to trigger a finding.
    | All values are relative change vs. the comparison period.
    */
    'thresholds' => [
        // Commerce metrics
        'revenue_decrease'             => 0.10,  // 10% drop
        'revenue_increase'             => 0.15,  // 15% increase
        'conversion_decrease'          => 0.10,  // 10% drop
        'conversion_increase'          => 0.15,  // 15% increase
        'aov_change'                   => 0.10,  // 10% change
        'returning_customer_decrease'  => 0.15,  // 15% drop
        'mobile_conversion_decrease'   => 0.15,  // 15% drop
        'traffic_source_conversion'    => 0.20,  // 20% change

        // Clarity behavioral metrics
        'rage_clicks_increase'         => 0.25,  // 25% increase
        'dead_clicks_increase'         => 0.25,  // 25% increase
        'quickbacks_increase'          => 0.20,  // 20% increase
        'script_errors_increase'       => 0.20,  // 20% increase
        'error_clicks_increase'        => 0.20,  // 20% increase
        'friction_score_increase'      => 0.20,  // 20% increase
    ],

    /*
    |--------------------------------------------------------------------------
    | Friction Score Weights
    |--------------------------------------------------------------------------
    | Weights must sum to 1.0. excessive_scrolling is intentionally excluded
    | as it is an ambiguous signal.
    */
    'friction_weights' => [
        'rage_clicks'   => 0.30,
        'dead_clicks'   => 0.25,
        'quick_backs'   => 0.20,
        'error_clicks'  => 0.15,
        'script_errors' => 0.10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Health Score Weights
    |--------------------------------------------------------------------------
    | Inputs used by HealthScoreCalculator (implemented Phase 4).
    */
    'health_score_weights' => [
        'revenue_trend'          => 0.35,
        'conversion_trend'       => 0.30,
        'friction_score'         => 0.20,
        'open_findings_severity' => 0.15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Level Thresholds (health score 0–100)
    |--------------------------------------------------------------------------
    */
    'risk_levels' => [
        'healthy'          => 75,  // score >= 75
        'attention_needed' => 50,  // score >= 50
        'at_risk'          => 25,  // score >= 25
        'critical'         => 0,   // score < 25
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Analyst Settings
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'model'           => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'gemini_api_key'  => env('GEMINI_API_KEY'),
        'max_tokens'      => (int) env('GEMINI_MAX_TOKENS', 1024),

        // Guardrail: AI must never claim to have watched session recordings.
        'behavioral_signal_phrase' => 'Clarity behavioral signals indicate',
    ],

    /*
    |--------------------------------------------------------------------------
    | Clarity Connector Settings
    |--------------------------------------------------------------------------
    */
    'clarity' => [
        'base_url'            => env('CLARITY_API_BASE_URL', 'https://www.clarity.ms/export-data/api/v1'),
        'daily_request_limit' => 10,
        'max_lookback_days'   => 3,
        'default_sync_days'   => 1,
        'initial_sync_days'   => 3,
        'dimension_groups'    => [
            'device_browser'  => ['Device', 'Browser'],
            'traffic_source'  => ['Source', 'Medium', 'Campaign'],
            'page_device'     => ['URL', 'Device'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nightly Analysis Schedule
    |--------------------------------------------------------------------------
    */
    'nightly_run_time' => env('INTELLIGENCE_NIGHTLY_RUN_TIME', '02:00'),

    /*
    |--------------------------------------------------------------------------
    | Comparison Periods
    |--------------------------------------------------------------------------
    */
    'comparison_periods' => [
        'day'    => 1,
        'week'   => 7,
        'month'  => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stage 2 Findings Engine Live Activation
    |--------------------------------------------------------------------------
    | When enabled, ChangeDetectionEngine routes candidate signals through
    | DataQualificationService and IncidentSynthesizer to emit structured,
    | qualified, deduplicated incidents into the findings table.
    */
    'stage2' => [
        'enabled' => env('STAGE2_FINDINGS_ENGINE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stage 2 Shadow Mode Live Parallel Evaluation Configuration
    |--------------------------------------------------------------------------
    | Parallel evaluation configuration to observe Stage 2 engine alongside
    | the production engine on newly arriving data for 14 consecutive days.
    | Guarantees zero writes to findings, zero status changes, zero notifications.
    |
    | Schedule Time: 06:00 UTC (01:00 AM EST). Aligns with Goldkamp's reporting
    | timezone (America/New_York, UTC-5). Midnight EST is 05:00 UTC; 06:00 UTC
    | guarantees the business day has fully closed and integration syncs have completed.
    */
    'shadow_mode' => [
        'enabled'          => env('STAGE2_SHADOW_MODE_ENABLED', false),
        'clients'          => [5], // Goldkamp
        'observation_days' => 14,
        'schedule_time'    => env('STAGE2_SHADOW_RUN_TIME', '06:00'),
        'storage_path'     => 'reports/shadow_mode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Business Opportunity Intelligence MVP 1 Defaults
    |--------------------------------------------------------------------------
    | Global baseline configuration for customer opportunity detectors.
    | Resolves customer-level overrides through ClientFindingConfiguration.
    */
    'opportunity_defaults' => [
        'opportunity.b2b_account_reactivation' => [
            'key'                  => 'opportunity.b2b_account_reactivation',
            'name'                 => 'High-Value Customer Reactivation',
            'enabled'              => true,
            'evaluation_frequency' => 'daily',
            'min_order_count'      => 3,
            'thresholds'           => [
                'dormancy_multiplier'   => 1.5,
                'max_dormancy_days'     => 180,
                'spend_percentile_tier' => 80,
                'min_spend_override'    => null,
                'min_client_history_days' => 180,
            ],
            'business_objectives' => [
                'priority_boost' => true,
                'strategic_goal' => 'B2B Wholesale Retention',
            ],
            'default_owner_role' => 'account_manager',
        ],
        'opportunity.replenishment_cadence' => [
            'key'                  => 'opportunity.replenishment_cadence',
            'name'                 => 'Repeat Purchasing & Replenishment',
            'enabled'              => true,
            'evaluation_frequency' => 'weekly',
            'min_order_count'      => 3,
            'thresholds'           => [
                'lead_window_days' => 5,
                'lag_window_days'  => 15,
                'max_variance_cv'  => 0.5,
            ],
            'business_objectives' => [
                'priority_boost' => false,
                'strategic_goal' => 'Consumable Reorder Capture',
            ],
            'default_owner_role' => 'growth_marketer',
        ],
        'risk.account_contraction' => [
            'key'                  => 'risk.account_contraction',
            'name'                 => 'Customer Revenue Contraction',
            'enabled'              => true,
            'evaluation_frequency' => 'weekly',
            'min_order_count'      => 3,
            'thresholds'           => [
                'contraction_threshold_pct' => 25.0,
                'baseline_period_days'      => 90,
                'evaluation_period_days'    => 90,
                'spend_percentile_tier'     => 70,
                'min_client_history_days'   => 180,
            ],
            'business_objectives' => [
                'priority_boost' => true,
                'strategic_goal' => 'Key Account Churn Prevention',
            ],
            'default_owner_role' => 'account_manager',
        ],
        'opportunity.long_term_winback' => [
            'key'                  => 'opportunity.long_term_winback',
            'name'                 => 'Long-Term Account Winback',
            'enabled'              => true,
            'evaluation_frequency' => 'monthly',
            'min_order_count'      => 3,
            'thresholds'           => [
                'min_dormancy_days'       => 181,
                'max_dormancy_days'       => 730,
                'spend_percentile_tier'   => 80,
                'min_client_history_days' => 365,
            ],
            'business_objectives' => [
                'priority_boost' => true,
                'strategic_goal' => 'Enterprise Winback Campaign',
            ],
            'default_owner_role' => 'executive_sales',
        ],
    ],

];


