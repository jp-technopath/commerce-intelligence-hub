import { z } from "zod";
export declare const name = "get_findings";
export declare const description = "Retrieve findings (anomalies, insights, issues) with their associated recommendation summaries. Filter by client, severity, status, or category.";
export declare const inputSchema: z.ZodObject<{
    client_id: z.ZodOptional<z.ZodNumber>;
    severity: z.ZodOptional<z.ZodArray<z.ZodString, "many">>;
    status: z.ZodOptional<z.ZodArray<z.ZodString, "many">>;
    category: z.ZodOptional<z.ZodArray<z.ZodString, "many">>;
    limit: z.ZodDefault<z.ZodNumber>;
}, "strip", z.ZodTypeAny, {
    limit: number;
    client_id?: number | undefined;
    status?: string[] | undefined;
    severity?: string[] | undefined;
    category?: string[] | undefined;
}, {
    client_id?: number | undefined;
    status?: string[] | undefined;
    severity?: string[] | undefined;
    category?: string[] | undefined;
    limit?: number | undefined;
}>;
export type Input = z.infer<typeof inputSchema>;
export declare function execute(input: Input): Promise<{
    filters: {
        client_id: number | undefined;
        severity: string[] | undefined;
        status: string[] | undefined;
        category: string[] | undefined;
        limit: number;
    };
    row_count: number | null;
    findings: any[];
}>;
//# sourceMappingURL=get-findings.d.ts.map