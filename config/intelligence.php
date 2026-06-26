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

];
