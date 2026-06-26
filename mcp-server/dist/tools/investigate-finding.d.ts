import { z } from "zod";
export declare const name = "investigate_finding";
export declare const description = "Pull a comprehensive investigation package for a specific finding. Includes: finding details, recommendations and outcomes, investigation notes, commerce and behavioral metrics around the detection date (\u00B17 days), deployments near the detection date (\u00B114 days), and similar past findings from intelligence memory.";
export declare const inputSchema: z.ZodObject<{
    finding_id: z.ZodNumber;
}, "strip", z.ZodTypeAny, {
    finding_id: number;
}, {
    finding_id: number;
}>;
export type Input = z.infer<typeof inputSchema>;
export declare function execute(input: Input): Promise<{
    error: string;
    finding?: undefined;
    recommendations?: undefined;
    investigation_notes?: undefined;
    context?: undefined;
    similar_past_findings?: undefined;
} | {
    finding: {
        id: any;
        client_id: any;
        finding_type: any;
        finding_category: any;
        title: any;
        description: any;
        severity: any;
        confidence_score: any;
        estimated_revenue_impact: any;
        status: any;
        detected_at: any;
        metadata: any;
    };
    recommendations: any[];
    investigation_notes: any[];
    context: {
        detection_date: string;
        commerce_metrics: {
            window: string;
            row_count: number | null;
            rows: any[];
        };
        behavioral_metrics: {
            window: string;
            row_count: number | null;
            rows: any[];
        };
        deployments: {
            window: string;
            row_count: number | null;
            rows: any[];
        };
    };
    similar_past_findings: {
        match_criteria: string;
        row_count: number | null;
        rows: any[];
    };
    error?: undefined;
}>;
//# sourceMappingURL=investigate-finding.d.ts.map