import { z } from "zod";
export declare const name = "query_deployments";
export declare const description = "Query deployment records for a client, optionally filtered by date range and deployment type.";
export declare const inputSchema: z.ZodObject<{
    client_id: z.ZodNumber;
    start_date: z.ZodOptional<z.ZodString>;
    end_date: z.ZodOptional<z.ZodString>;
    deployment_type: z.ZodOptional<z.ZodString>;
}, "strip", z.ZodTypeAny, {
    client_id: number;
    start_date?: string | undefined;
    end_date?: string | undefined;
    deployment_type?: string | undefined;
}, {
    client_id: number;
    start_date?: string | undefined;
    end_date?: string | undefined;
    deployment_type?: string | undefined;
}>;
export type Input = z.infer<typeof inputSchema>;
export declare function execute(input: Input): Promise<{
    client_id: number;
    filters: {
        start_date: string | undefined;
        end_date: string | undefined;
        deployment_type: string | undefined;
    };
    row_count: number | null;
    deployments: any[];
}>;
//# sourceMappingURL=query-deployments.d.ts.map