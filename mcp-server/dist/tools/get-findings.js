"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.inputSchema = exports.description = exports.name = void 0;
exports.execute = execute;
const zod_1 = require("zod");
const db_js_1 = __importDefault(require("../db.js"));
exports.name = "get_findings";
exports.description = "Retrieve findings (anomalies, insights, issues) with their associated recommendation summaries. Filter by client, severity, status, or category.";
exports.inputSchema = zod_1.z.object({
    client_id: zod_1.z
        .number()
        .int()
        .optional()
        .describe("Optional client ID to filter findings for a specific client"),
    severity: zod_1.z
        .array(zod_1.z.string())
        .optional()
        .describe("Optional array of severity levels to filter (e.g. ['high','critical'])"),
    status: zod_1.z
        .array(zod_1.z.string())
        .optional()
        .describe("Optional array of statuses to filter (e.g. ['open','investigating'])"),
    category: zod_1.z
        .array(zod_1.z.string())
        .optional()
        .describe("Optional array of finding categories to filter"),
    limit: zod_1.z
        .number()
        .int()
        .min(1)
        .max(100)
        .default(20)
        .describe("Maximum number of findings to return (default: 20, max: 100)"),
});
async function execute(input) {
    const { client_id, severity, status, category, limit } = input;
    const conditions = [];
    const params = [];
    let paramIdx = 0;
    if (client_id !== undefined) {
        paramIdx++;
        conditions.push(`f.client_id = $${paramIdx}`);
        params.push(client_id);
    }
    if (severity && severity.length > 0) {
        paramIdx++;
        conditions.push(`f.severity = ANY($${paramIdx}::text[])`);
        params.push(severity);
    }
    if (status && status.length > 0) {
        paramIdx++;
        conditions.push(`f.status = ANY($${paramIdx}::text[])`);
        params.push(status);
    }
    if (category && category.length > 0) {
        paramIdx++;
        conditions.push(`f.finding_category = ANY($${paramIdx}::text[])`);
        params.push(category);
    }
    paramIdx++;
    params.push(limit);
    const whereClause = conditions.length > 0 ? `WHERE ${conditions.join(" AND ")}` : "";
    const query = `
    SELECT
      f.id AS finding_id,
      f.client_id,
      f.finding_type,
      f.finding_category,
      f.title,
      f.description,
      f.severity,
      f.confidence_score,
      f.estimated_revenue_impact,
      f.status,
      f.detected_at,
      f.metadata_json,
      COALESCE(
        json_agg(
          json_build_object(
            'recommendation_id', r.id,
            'recommendation_text', r.recommendation_text,
            'ai_summary', r.ai_summary,
            'model_used', r.model_used
          )
        ) FILTER (WHERE r.id IS NOT NULL),
        '[]'::json
      ) AS recommendations
    FROM findings f
    LEFT JOIN recommendations r ON r.finding_id = f.id
    ${whereClause}
    GROUP BY f.id
    ORDER BY f.detected_at DESC
    LIMIT $${paramIdx}
  `;
    const result = await db_js_1.default.query(query, params);
    return {
        filters: { client_id, severity, status, category, limit },
        row_count: result.rowCount,
        findings: result.rows,
    };
}
//# sourceMappingURL=get-findings.js.map