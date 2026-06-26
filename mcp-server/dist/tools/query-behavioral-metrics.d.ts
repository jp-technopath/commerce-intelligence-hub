import { z } from "zod";
export declare const name = "query_behavioral_metrics";
export declare const description = "Query behavioral / UX metrics (traffic, rage clicks, dead clicks, quick backs, script errors, scroll depth, engagement time, friction score) for a client over a date range.";
export declare const inputSchema: z.ZodObject<{
    client_id: z.ZodNumber;
    start_date: z.ZodString;
    end_date: z.ZodString;
}, "strip", z.ZodTypeAny, {
    client_id: number;
    start_date: string;
    end_date: string;
}, {
    client_id: number;
    start_date: string;
    end_date: string;
}>;
export type Input = z.infer<typeof inputSchema>;
export declare function execute(input: Input): Promise<{
    client_id: number;
    start_date: string;
    end_date: string;
    row_count: number | null;
    rows: any[];
}>;
//# sourceMappingURL=query-behavioral-metrics.d.ts.map