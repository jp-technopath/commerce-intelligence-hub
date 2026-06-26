"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || (function () {
    var ownKeys = function(o) {
        ownKeys = Object.getOwnPropertyNames || function (o) {
            var ar = [];
            for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) ar[ar.length] = k;
            return ar;
        };
        return ownKeys(o);
    };
    return function (mod) {
        if (mod && mod.__esModule) return mod;
        var result = {};
        if (mod != null) for (var k = ownKeys(mod), i = 0; i < k.length; i++) if (k[i] !== "default") __createBinding(result, mod, k[i]);
        __setModuleDefault(result, mod);
        return result;
    };
})();
Object.defineProperty(exports, "__esModule", { value: true });
require("dotenv/config");
const mcp_js_1 = require("@modelcontextprotocol/sdk/server/mcp.js");
const stdio_js_1 = require("@modelcontextprotocol/sdk/server/stdio.js");
const queryCommerceMetrics = __importStar(require("./tools/query-commerce-metrics.js"));
const queryBehavioralMetrics = __importStar(require("./tools/query-behavioral-metrics.js"));
const getFindings = __importStar(require("./tools/get-findings.js"));
const getRecommendations = __importStar(require("./tools/get-recommendations.js"));
const comparePeriods = __importStar(require("./tools/compare-periods.js"));
const queryDeployments = __importStar(require("./tools/query-deployments.js"));
const correlateMetrics = __importStar(require("./tools/correlate-metrics.js"));
const investigateFinding = __importStar(require("./tools/investigate-finding.js"));
// ─── Server Setup ───────────────────────────────────────────────────────────
const server = new mcp_js_1.McpServer({
    name: "technopath-commerce-intelligence",
    version: "1.0.0",
});
async function safeTool(schema, execute, args) {
    try {
        const parsed = schema.parse(args);
        const result = await execute(parsed);
        return {
            content: [
                { type: "text", text: JSON.stringify(result, null, 2) },
            ],
        };
    }
    catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        return {
            content: [
                {
                    type: "text",
                    text: JSON.stringify({ error: message }, null, 2),
                },
            ],
            isError: true,
        };
    }
}
// ─── Tool registry ──────────────────────────────────────────────────────────
const tools = [
    queryCommerceMetrics,
    queryBehavioralMetrics,
    getFindings,
    getRecommendations,
    comparePeriods,
    queryDeployments,
    correlateMetrics,
    investigateFinding,
];
for (const tool of tools) {
    const schema = tool.inputSchema;
    const exec = tool.execute;
    server.tool(tool.name, tool.description, schema.shape, (args) => safeTool(schema, exec, args));
}
// ─── Start Server ───────────────────────────────────────────────────────────
async function main() {
    const transport = new stdio_js_1.StdioServerTransport();
    await server.connect(transport);
    console.error("Technopath Commerce Intelligence MCP server running on stdio");
}
main().catch((err) => {
    console.error("Fatal error starting MCP server:", err);
    process.exit(1);
});
//# sourceMappingURL=index.js.map