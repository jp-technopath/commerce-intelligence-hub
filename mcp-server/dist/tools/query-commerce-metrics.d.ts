import { z } from "zod";
export declare const name = "query_commerce_metrics";
export declare const description = "Query commerce metrics (sessions, revenue, orders, conversion rate, AOV, new customers) for a client over a date range, optionally filtered by data source and grouped by day or week.";
export declare const inputSchema: z.ZodObject<{
    client_id: z.ZodNumber;
    start_date: z.ZodString;
    end_date: z.ZodString;
    source: z.ZodDefault<z.ZodEnum<["ga4", "adobe_commerce", "all"]>>;
    group_by: z.ZodDefault<z.ZodEnum<["day", "week"]>>;
}, "strip", z.ZodTypeAny, {
    client_id: number;
    start_date: string;
    end_date: string;
    source: "ga4" | "adobe_commerce" | "all";
    group_by: "day" | "week";
}, {
    client_id: number;
    start_date: string;
    end_date: string;
    source?: "ga4" | "adobe_commerce" | "all" | undefined;
    group_by?: "day" | "week" | undefined;
}>;
export type Input = z.infer<typeof inputSchema>;
export declare function execute(input: Input): Promise<{
    client_id: number;
    start_date: string;
    end_date: string;
    source: "ga4" | "adobe_commerce" | "all";
    group_by: "day" | "week";
    row_count: number | null;
    rows: any[];
}>;
//# sourceMappingURL=query-commerce-metrics.d.ts.map