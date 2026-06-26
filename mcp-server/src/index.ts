import "dotenv/config";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

import * as queryCommerceMetrics from "./tools/query-commerce-metrics.js";
import * as queryBehavioralMetrics from "./tools/query-behavioral-metrics.js";
import * as getFindings from "./tools/get-findings.js";
import * as getRecommendations from "./tools/get-recommendations.js";
import * as comparePeriods from "./tools/compare-periods.js";
import * as queryDeployments from "./tools/query-deployments.js";
import * as correlateMetrics from "./tools/correlate-metrics.js";
import * as investigateFinding from "./tools/investigate-finding.js";

// ─── Server Setup ───────────────────────────────────────────────────────────

const server = new McpServer({
  name: "technopath-commerce-intelligence",
  version: "1.0.0",
});

// ─── Helper: error-safe executor ────────────────────────────────────────────

type AnyZodObject = z.ZodObject<z.ZodRawShape>;

async function safeTool(
  schema: AnyZodObject,
  execute: (input: z.output<AnyZodObject>) => Promise<unknown>,
  args: Record<string, unknown>
) {
  try {
    const parsed = schema.parse(args);
    const result = await execute(parsed);
    return {
      content: [
        { type: "text" as const, text: JSON.stringify(result, null, 2) },
      ],
    };
  } catch (err: unknown) {
    const message = err instanceof Error ? err.message : String(err);
    return {
      content: [
        {
          type: "text" as const,
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
] as const;

for (const tool of tools) {
  const schema = tool.inputSchema as AnyZodObject;
  const exec = tool.execute as (input: z.output<AnyZodObject>) => Promise<unknown>;

  server.tool(
    tool.name,
    tool.description,
    schema.shape,
    (args) => safeTool(schema, exec, args)
  );
}

// ─── Start Server ───────────────────────────────────────────────────────────

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error(
    "Technopath Commerce Intelligence MCP server running on stdio"
  );
}

main().catch((err) => {
  console.error("Fatal error starting MCP server:", err);
  process.exit(1);
});
