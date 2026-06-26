import { z } from "zod";
import pool from "../db.js";

export const name = "get_recommendations";

export const description =
  "Retrieve recommendations with their outcome status. Filter by finding, client, or implementation status.";

export const inputSchema = z.object({
  finding_id: z
    .number()
    .int()
    .optional()
    .describe("Optional finding ID to get recommendations for a specific finding"),
  client_id: z
    .number()
    .int()
    .optional()
    .describe("Optional client ID to get recommendations across all findings for a client"),
  implemented_only: z
    .boolean()
    .default(false)
    .describe("If true, return only recommendations that have been implemented"),
});

export type Input = z.infer<typeof inputSchema>;

export async function execute(input: Input) {
  const { finding_id, client_id, implemented_only } = input;

  const conditions: string[] = [];
  const params: (number | boolean)[] = [];
  let paramIdx = 0;

  if (finding_id !== undefined) {
    paramIdx++;
    conditions.push(`r.finding_id = $${paramIdx}`);
    params.push(finding_id);
  }

  if (client_id !== undefined) {
    paramIdx++;
    conditions.push(`f.client_id = $${paramIdx}`);
    params.push(client_id);
  }

  if (implemented_only) {
    conditions.push("ro.implemented = true");
  }

  const whereClause =
    conditions.length > 0 ? `WHERE ${conditions.join(" AND ")}` : "";

  const query = `
    SELECT
      r.id AS recommendation_id,
      r.finding_id,
      f.client_id,
      f.title AS finding_title,
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
    INNER JOIN findings f ON f.id = r.finding_id
    LEFT JOIN recommendation_outcomes ro ON ro.recommendation_id = r.id
    ${whereClause}
    ORDER BY r.id DESC
  `;

  const result = await pool.query(query, params);

  return {
    filters: { finding_id, client_id, implemented_only },
    row_count: result.rowCount,
    recommendations: result.rows,
  };
}
