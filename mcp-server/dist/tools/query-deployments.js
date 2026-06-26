"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.inputSchema = exports.description = exports.name = void 0;
exports.execute = execute;
const zod_1 = require("zod");
const db_js_1 = __importDefault(require("../db.js"));
exports.name = "query_deployments";
exports.description = "Query deployment records for a client, optionally filtered by date range and deployment type.";
exports.inputSchema = zod_1.z.object({
    client_id: zod_1.z.number().int().describe("The client ID to query deployments for"),
    start_date: zod_1.z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/)
        .optional()
        .describe("Optional start date filter (YYYY-MM-DD)"),
    end_date: zod_1.z
        .string()
        .regex(/^\d{4}-\d{2}-\d{2}$/)
        .optional()
        .describe("Optional end date filter (YYYY-MM-DD)"),
    deployment_type: zod_1.z
        .string()
        .optional()
        .describe("Optional deployment type filter (e.g. 'release', 'hotfix', 'config_change')"),
});
async function execute(input) {
    const { client_id, start_date, end_date, deployment_type } = input;
    const conditions = ["client_id = $1"];
    const params = [client_id];
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
    const result = await db_js_1.default.query(query, params);
    return {
        client_id,
        filters: { start_date, end_date, deployment_type },
        row_count: result.rowCount,
        deployments: result.rows,
    };
}
//# sourceMappingURL=query-deployments.js.map