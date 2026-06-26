import { z } from "zod";
import pool from "../db.js";

export const name = "query_deployments";

export const description =
  "Query deployment records for a client, optionally filtered by date range and deployment type.";

export const inputSchema = z.object({
  client_id: z.number().int().describe("The client ID to query deployments for"),
  start_date: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/)
    .optional()
    .describe("Optional start date filter (YYYY-MM-DD)"),
  end_date: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/)
    .optional()
    .describe("Optional end date filter (YYYY-MM-DD)"),
  deployment_type: z
    .string()
    .optional()
    .describe("Optional deployment type filter (e.g. 'release', 'hotfix', 'config_change')"),
});

export type Input = z.infer<typeof inputSchema>;

export async function execute(input: Input) {
  const { client_id, start_date, end_date, deployment_type } = input;

  const conditions: string[] = ["client_id = $1"];
  const params: (string | number)[] = [client_id];
  let paramIdx = 1;

  if (start_date) {
    paramIdx++;
    conditions.push(`deployed_at >= $${paramIdx}::date`);
    params.push(start_date);
  }

  if (end_date) {
    paramIdx++;
    conditions.push(`deployed_at <= ($${paramIdx}::date + interval '1 day')`);
    params.push(end_date);
  }

  if (deployment_type) {
    paramIdx++;
    conditions.push(`deployment_type = $${paramIdx}`);
    params.push(deployment_type);
  }

  const query = `
    SELECT
      id,
      client_id,
      title,
      deployment_type,
      description,
      deployed_by,
      deployed_at,
      metadata_json
    FROM deployments
    WHERE ${conditions.join(" AND ")}
    ORDER BY deployed_at DESC
  `;

  const result = await pool.query(query, params);

  return {
    client_id,
    filters: { start_date, end_date, deployment_type },
    row_count: result.rowCount,
    deployments: result.rows,
  };
}
