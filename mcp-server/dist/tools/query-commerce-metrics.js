"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.inputSchema = exports.description = exports.name = void 0;
exports.execute = execute;
const zod_1 = require("zod");
const db_js_1 = __importDefault(require("../db.js"));
exports.name = "query_commerce_metrics";
exports.description = "Query commerce metrics (sessions, revenue, orders, conversion rate, AOV, new customers) for a client over a date range, optionally filtered by data source and grouped by day or week.";
exports.inputSchema = zod_1.z.object({
    client_id: zod_1.z.number().int().describe("The client ID to query metrics for"),
    start_date: zod_1.z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/)
        .describe("Start date in YYYY-MM-DD format"),
    end_date: zod_1.z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/)
        .describe("End date in YYYY-MM-DD format"),
    source: zod_1.z
        .enum(["ga4", "adobe_commerce", "all"])
        .default("all")
        .describe("Data source filter: ga4, adobe_commerce, or all (default: all)"),
    group_by: zod_1.z
        .enum(["day", "week"])
        .default("day")
        .describe("Group results by day or week (default: day)"),
});
async function execute(input) {
    const { client_id, start_date, end_date, source, group_by } = input;
    const dateExpr = group_by === "week"
        ? "date_trunc('week', date)::date"
        : "date";
    const conditions = [
        "client_id = $1",
        "date >= $2",
        "date <= $3",
    ];
    const params = [client_id, start_date, end_date];
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
    const result = await db_js_1.default.query(query, params);
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
//# sourceMappingURL=query-commerce-metrics.js.map