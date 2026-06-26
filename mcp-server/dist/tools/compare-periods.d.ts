import { z } from "zod";
export declare const name = "compare_periods";
export declare const description = "Compare commerce and behavioral metrics between two time periods. Returns aggregated totals for each period and the percentage change for every metric.";
export declare const inputSchema: z.ZodObject<{
    client_id: z.ZodNumber;
    current_start: z.ZodString;
    current_end: z.ZodString;
    previous_start: z.ZodString;
    previous_end: z.ZodString;
}, "strip", z.ZodTypeAny, {
    client_id: number;
    current_start: string;
    current_end: string;
    previous_start: string;
    previous_end: string;
}, {
    client_id: number;
    current_start: string;
    current_end: string;
    previous_start: string;
    previous_end: string;
}>;
export type Input = z.infer<typeof inputSchema>;
export declare function execute(input: Input): Promise<{
    client_id: number;
    current_period: {
        sessions: number;
        new_customers: number;
        revenue: number;
        orders: number;
        conversion_rate: number;
        aov: number;
        traffic: number;
        rage_clicks: number;
        dead_clicks: number;
        quick_backs: number;
        script_errors: number;
        error_clicks: number;
        avg_scroll_depth: number;
        avg_engagement_time: number;
        avg_friction_score: number;
        start: string;
        end: string;
    };
    previous_period: {
        sessions: number;
        new_customers: number;
        revenue: number;
        orders: number;
        conversion_rate: number;
        aov: number;
        traffic: number;
        rage_clicks: number;
        dead_clicks: number;
        quick_backs: number;
        script_errors: number;
        error_clicks: number;
        avg_scroll_depth: number;
        avg_engagement_time: number;
        avg_friction_score: number;
        start: string;
        end: string;
    };
    changes: Record<string, {
        value: number;
        previous_value: number;
        pct_change: number | null;
    }>;
}>;
//# sourceMappingURL=compare-periods.d.ts.map