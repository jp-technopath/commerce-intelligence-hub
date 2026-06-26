"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.inputSchema = exports.description = exports.name = void 0;
exports.execute = execute;
const zod_1 = require("zod");
const db_js_1 = __importDefault(require("../db.js"));
exports.name = "query_behavioral_metrics";
exports.description = "Query behavioral / UX metrics (traffic, rage clicks, dead clicks, quick backs, script errors, scroll depth, engagement time, friction score) for a client over a date range.";
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
});
async function execute(input) {
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
    const result = await db_js_1.default.query(query, [client_id, start_date, end_date]);
    return {
        client_id,
        start_date,
        end_date,
        row_count: result.rowCount,
        rows: result.rows,
    };
}
//# sourceMappingURL=query-behavioral-metrics.js.map