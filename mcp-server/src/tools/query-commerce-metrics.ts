import { z } from "zod";
import pool from "../db.js";

export const name = "query_commerce_metrics";

export const description =
  "Query commerce metrics (sessions, revenue, orders, conversion rate, AOV, new customers) for a client over a date range, optionally filtered by data source and grouped by day or week.";

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
  source: z
    .enum(["ga4", "adobe_commerce", "all"])
    .default("all")
    .describe("Data source filter: ga4, adobe_commerce, or all (default: all)"),
  group_by: z
    .enum(["day", "week"])
    .default("day")
    .describe("Group results by day or week (default: day)"),
});

export type Input = z.infer<typeof inputSchema>;

export async function execute(input: Input) {
  const { client_id, start_date, end_date, source, group_by } = input;

  const dateExpr =
    group_by === "week"
      ? "date_trunc('week', date)::date"
      : "date";

  const conditions: string[] = [
    "client_id = $1",
    "date >= $2",
    "date <= $3",
  ];
  const params: (string | number)[] = [client_id, start_date, end_date];

  if (source !== "all") {
    conditions.push(`source = $${params.length + 1}`);
    params.push(source);
  }

  const whereClause = conditions.join(" AND ");

  const query = `
    SELECT
      ${dateExpr} AS period,
      ${source === "all" ? "'all'" : "source"} AS source,
      SUM(sessions)::int AS sessions,
      SUM(new_customers)::int AS new_customers,
      SUM(revenue)::numeric(14,2) AS revenue,
      SUM(orders)::int AS orders,
      CASE
        WHEN SUM(sessions) > 0
        THEN ROUND(SUM(orders)::numeric / SUM(sessions) * 100, 4)
        ELSE 0
      END AS conversion_rate,
      CASE
        WHEN SUM(orders) > 0
        THEN ROUND(SUM(revenue) / SUM(orders), 2)
        ELSE 0
      END AS aov
    FROM commerce_metrics
    WHERE ${whereClause}
    GROUP BY ${dateExpr}${source !== "all" ? ", source" : ""}
    ORDER BY period ASC
  `;

  const result = await pool.query(query, params);

  return {
    client_id,
    start_date,
    end_date,
    source,
    group_by,
    row_count: result.rowCount,
    rows: result.rows,
  };
}
