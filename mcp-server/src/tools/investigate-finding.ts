import { z } from "zod";
import pool from "../db.js";

export const name = "investigate_finding";

export const description =
  "Pull a comprehensive investigation package for a specific finding. Includes: finding details, recommendations and outcomes, investigation notes, commerce and behavioral metrics around the detection date (±7 days), deployments near the detection date (±14 days), and similar past findings from intelligence memory.";

export const inputSchema = z.object({
  finding_id: z
    .number()
    .int()
    .describe("The finding ID to investigate"),
});

export type Input = z.infer<typeof inputSchema>;

export async function execute(input: Input) {
  const { finding_id } = input;

  // 1. Finding details
  const findingResult = await pool.query(
    `SELECT * FROM findings WHERE id = $1`,
    [finding_id]
  );

  if (findingResult.rowCount === 0) {
    return { error: `Finding with id ${finding_id} not found` };
  }

  const finding = findingResult.rows[0];
  const clientId = finding.client_id;
  const detectedAt: string = finding.detected_at;

  // 2. Recommendations + outcomes
  const recsResult = await pool.query(
    `
    SELECT
      r.id AS recommendation_id,
      r.recommendation_text,
      r.ai_summary,
      r.confidence_reasoning,
      r.model_used,
      ro.id AS outcome_id,
      ro.implemented,
      ro.implemented_at,
      ro.estimated_impact,
      ro.actual_impact,
      ro.outcome_notes
    FROM recommendations r
    LEFT JOIN recommendation_outcomes ro ON ro.recommendation_id = r.id
    WHERE r.finding_id = $1
    ORDER BY r.id
    `,
    [finding_id]
  );

  // 3. Investigation notes
  const notesResult = await pool.query(
    `
    SELECT
      id,
      user_id,
      root_cause,
      fix_implemented,
      outcome,
      lessons_learned
    FROM investigation_notes
    WHERE finding_id = $1
    ORDER BY id
    `,
    [finding_id]
  );

  // 4. Commerce metrics ±7 days around detected_at
  const commerceResult = await pool.query(
    `
    SELECT
      date,
      source,
      sessions,
      new_customers,
      revenue,
      orders,
      conversion_rate,
      aov
    FROM commerce_metrics
    WHERE client_id = $1
      AND date >= ($2::date - interval '7 days')
      AND date <= ($2::date + interval '7 days')
    ORDER BY date ASC
    `,
    [clientId, detectedAt]
  );

  // 5. Behavioral metrics ±7 days around detected_at
  const behavioralResult = await pool.query(
    `
    SELECT
      date,
      traffic,
      rage_clicks,
      dead_clicks,
      quick_backs,
      excessive_scrolling,
      script_errors,
      error_clicks,
      scroll_depth,
      engagement_time,
      friction_score
    FROM behavioral_metrics
    WHERE client_id = $1
      AND date >= ($2::date - interval '7 days')
      AND date <= ($2::date + interval '7 days')
    ORDER BY date ASC
    `,
    [clientId, detectedAt]
  );

  // 6. Deployments ±14 days around detected_at
  const deploymentsResult = await pool.query(
    `
    SELECT
      id,
      title,
      deployment_type,
      description,
      deployed_by,
      deployed_at,
      metadata_json
    FROM deployments
    WHERE client_id = $1
      AND deployed_at >= ($2::date - interval '14 days')
      AND deployed_at <= ($2::date + interval '14 days')
    ORDER BY deployed_at ASC
    `,
    [clientId, detectedAt]
  );

  // 7. Similar past findings from intelligence_memory
  const memoryResult = await pool.query(
    `
    SELECT
      id,
      finding_type,
      finding_category,
      pattern_description,
      root_cause,
      resolution,
      outcome,
      metadata_json
    FROM intelligence_memory
    WHERE client_id = $1
      AND (
        finding_type = $2
        OR finding_category = $3
      )
    ORDER BY id DESC
    LIMIT 10
    `,
    [clientId, finding.finding_type, finding.finding_category]
  );

  return {
    finding: {
      id: finding.id,
      client_id: finding.client_id,
      finding_type: finding.finding_type,
      finding_category: finding.finding_category,
      title: finding.title,
      description: finding.description,
      severity: finding.severity,
      confidence_score: finding.confidence_score,
      estimated_revenue_impact: finding.estimated_revenue_impact,
      status: finding.status,
      detected_at: finding.detected_at,
      metadata: finding.metadata_json,
    },
    recommendations: recsResult.rows,
    investigation_notes: notesResult.rows,
    context: {
      detection_date: detectedAt,
      commerce_metrics: {
        window: "±7 days from detection",
        row_count: commerceResult.rowCount,
        rows: commerceResult.rows,
      },
      behavioral_metrics: {
        window: "±7 days from detection",
        row_count: behavioralResult.rowCount,
        rows: behavioralResult.rows,
      },
      deployments: {
        window: "±14 days from detection",
        row_count: deploymentsResult.rowCount,
        rows: deploymentsResult.rows,
      },
    },
    similar_past_findings: {
      match_criteria: `finding_type='${finding.finding_type}' OR finding_category='${finding.finding_category}'`,
      row_count: memoryResult.rowCount,
      rows: memoryResult.rows,
    },
  };
}
