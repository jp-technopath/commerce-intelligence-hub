import { z } from "zod";
export declare const name = "correlate_metrics";
export declare const description = "Analyze correlations between commerce and behavioral metrics over the last N days for a client. Detects patterns such as revenue vs. friction score, traffic vs. conversions, rage clicks vs. conversion rate, and more.";
export declare const inputSchema: z.ZodObject<{
    client_id: z.ZodNumber;
    days: z.ZodDefault<z.ZodNumber>;
}, "strip", z.ZodTypeAny, {
    client_id: number;
    days: number;
}, {
    client_id: number;
    days?: number | undefined;
}>;
export type Input = z.infer<typeof inputSchema>;
export declare function execute(input: Input): Promise<{
    client_id: number;
    days: number;
    data_points: number;
    message: string;
    correlations: never[];
} | {
    client_id: number;
    days: number;
    data_points: number;
    correlations: {
        metric_a: string;
        metric_b: string;
        hypothesis: string;
        correlation_coefficient: number | null;
        interpretation: string;
    }[];
    message?: undefined;
}>;
//# sourceMappingURL=correlate-metrics.d.ts.map