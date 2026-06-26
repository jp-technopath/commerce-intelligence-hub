import { z } from "zod";
export declare const name = "get_recommendations";
export declare const description = "Retrieve recommendations with their outcome status. Filter by finding, client, or implementation status.";
export declare const inputSchema: z.ZodObject<{
    finding_id: z.ZodOptional<z.ZodNumber>;
    client_id: z.ZodOptional<z.ZodNumber>;
    implemented_only: z.ZodDefault<z.ZodBoolean>;
}, "strip", z.ZodTypeAny, {
    implemented_only: boolean;
    client_id?: number | undefined;
    finding_id?: number | undefined;
}, {
    client_id?: number | undefined;
    finding_id?: number | undefined;
    implemented_only?: boolean | undefined;
}>;
export type Input = z.infer<typeof inputSchema>;
export declare function execute(input: Input): Promise<{
    filters: {
        finding_id: number | undefined;
        client_id: number | undefined;
        implemented_only: boolean;
    };
    row_count: number | null;
    recommendations: any[];
}>;
//# sourceMappingURL=get-recommendations.d.ts.map