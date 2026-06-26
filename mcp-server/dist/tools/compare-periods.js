"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.inputSchema = exports.description = exports.name = void 0;
exports.execute = execute;
const zod_1 = require("zod");
const db_js_1 = __importDefault(require("../db.js"));
exports.name = "compare_periods";
exports.description = "Compare commerce and behavioral metrics between two time periods. Returns aggregated totals for each period and the percentage change for every metric.";
exports.inputSchema = zod_1.z.object({
    client_id: zod_1.z.number().int().describe("The client ID to compare periods for"),
    current_start: zod_1.z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/)
        .describe("Start date of the current period (YYYY-MM-DD)"),
    current_end: zod_1.z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/)
        .describe("End date of the current period (YYYY-MM-DD)"),
    previous_start: zod_1.z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/)
        .describe("Start date of the previous period (YYYY-MM-DD)"),
    previous_end: zod_1.z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/)
        .describe("End date of the previous period (YYYY-MM-DD)"),
});
async function aggregatePeriod(clientId, startDate, endDate) {
    const commerceQuery = `
    SELECT
      COALESCE(SUM(sessions), 0)::int AS sessions,
      COALESCE(SUM(new_customers), 0)::int AS new_customers,
      COALESCE(SUM(revenue), 0)::numeric(14,2) AS revenue,
      COALESCE(SUM(orders), 0)::int AS orders,
      CASE WHEN SUM(sessions) > 0
        THEN ROUND(SUM(orders)::numeric / SUM(sessions) * 100, 4)
        ELSE 0 END AS conversion_rate,
      CASE WHEN SUM(orders) > 0
        THEN ROUND(SUM(revenue) / SUM(orders), 2)
        ELSE 0 END AS aov
    FROM commerce_metrics
    WHERE client_id = $1 AND date >= $2 AND date <= $3
  `;
    const behavioralQuery = `
    SELECT
      COALESCE(SUM(traffic), 0)::int AS traffic,
      COALESCE(SUM(rage_clicks), 0)::int AS rage_clicks,
      COALESCE(SUM(dead_clicks), 0)::int AS dead_clicks,
      COALESCE(SUM(quick_backs), 0)::int AS quick_backs,
      COALESCE(SUM(script_errors), 0)::int AS script_errors,
      COALESCE(SUM(error_clicks), 0)::int AS error_clicks,
      COALESCE(ROUND(AVG(scroll_depth)::numeric, 2), 0) AS avg_scroll_depth,
      COALESCE(ROUND(AVG(engagement_time)::numeric, 2), 0) AS avg_engagement_time,
      COALESCE(ROUND(AVG(friction_score)::numeric, 4), 0) AS avg_friction_score
    FROM behavioral_metrics
    WHERE client_id = $1 AND date >= $2 AND date <= $3
  `;
    const params = [clientId, startDate, endDate];
    const [commerce, behavioral] = await Promise.all([
        db_js_1.default.query(commerceQuery, params),
        db_js_1.default.query(behavioralQuery, params),
    ]);
    const c = commerce.rows[0];
    const b = behavioral.rows[0];
    return {
        sessions: Number(c.sessions),
        new_customers: Number(c.new_customers),
        revenue: Number(c.revenue),
        orders: Number(c.orders),
        conversion_rate: Number(c.conversion_rate),
        aov: Number(c.aov),
        traffic: Number(b.traffic),
        rage_clicks: Number(b.rage_clicks),
        dead_clicks: Number(b.dead_clicks),
        quick_backs: Number(b.quick_backs),
        script_errors: Number(b.script_errors),
        error_clicks: Number(b.error_clicks),
        avg_scroll_depth: Number(b.avg_scroll_depth),
        avg_engagement_time: Number(b.avg_engagement_time),
        avg_friction_score: Number(b.avg_friction_score),
    };
}
function calcChange(current, previous) {
    const changes = {};
    for (const key of Object.keys(current)) {
        const cur = current[key];
        const prev = previous[key];
        const pctChange = prev !== 0
            ? Math.round(((cur - prev) / Math.abs(prev)) * 10000) / 100
            : cur !== 0
                ? 100
                : null;
        changes[key] = {
            value: cur,
            previous_value: prev,
            pct_change: pctChange,
        };
    }
    return changes;
}
async function execute(input) {
    const { client_id, current_start, current_end, previous_start, previous_end } = input;
    const [current, previous] = await Promise.all([
        aggregatePeriod(client_id, current_start, current_end),
        aggregatePeriod(client_id, previous_start, previous_end),
    ]);
    return {
        client_id,
        current_period: { start: current_start, end: current_end, ...current },
        previous_period: { start: previous_start, end: previous_end, ...previous },
        changes: calcChange(current, previous),
    };
}
//# sourceMappingURL=compare-periods.js.map