import { z } from "zod";
import pool from "../db.js";

export const name = "query_behavioral_metrics";

export const description =
  "Query behavioral / UX metrics (traffic, rage clicks, dead clicks, quick backs, script errors, scroll depth, engagement time, friction score) for a client over a date range.";

export const inputSchema = z.object({
  client_id: z.number().int().describe("The client ID to query metrics for"),
  start_date: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/)
    .describe("Start date in YYYY-MM-DD format"),
  end_date: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/)
    .describe("End date in YYYY-MM-DD format"),
});

export type Input = z.infer<typeof inputSchema>;

export async function execute(input: Input) {
  const { client_id, start_date, end_date } = input;

  const query = `
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
      friction_score,
      metadata_json
    FROM behavioral_metrics
    WHERE client_id = $1
      AND date >= $2
      AND date <= $3
    ORDER BY date ASC
  `;

  const result = await pool.query(query, [client_id, start_date, end_date]);

  return {
    client_id,
    start_date,
    end_date,
    row_count: result.rowCount,
    rows: result.rows,
  };
}
