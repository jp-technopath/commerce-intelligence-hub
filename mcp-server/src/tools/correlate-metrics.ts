import { z } from "zod";
import pool from "../db.js";

export const name = "correlate_metrics";

export const description =
  "Analyze correlations between commerce and behavioral metrics over the last N days for a client. Detects patterns such as revenue vs. friction score, traffic vs. conversions, rage clicks vs. conversion rate, and more.";

export const inputSchema = z.object({
  client_id: z.number().int().describe("The client ID to analyze correlations for"),
  days: z
    .number()
    .int()
    .min(3)
    .max(90)
    .default(14)
    .describe("Number of days to look back (default: 14, min: 3, max: 90)"),
});

export type Input = z.infer<typeof inputSchema>;

interface DayRow {
  date: string;
  revenue: number;
  sessions: number;
  orders: number;
  conversion_rate: number;
  aov: number;
  traffic: number;
  rage_clicks: number;
  dead_clicks: number;
  friction_score: number;
  script_errors: number;
  engagement_time: number;
}

/**
 * Pearson correlation coefficient between two numeric arrays.
 */
function pearson(x: number[], y: number[]): number | null {
  const n = x.length;
  if (n < 3) return null;

  const meanX = x.reduce((s, v) => s + v, 0) / n;
  const meanY = y.reduce((s, v) => s + v, 0) / n;

  let num = 0;
  let denX = 0;
  let denY = 0;
  for (let i = 0; i < n; i++) {
    const dx = x[i] - meanX;
    const dy = y[i] - meanY;
    num += dx * dy;
    denX += dx * dx;
    denY += dy * dy;
  }

  const den = Math.sqrt(denX * denY);
  if (den === 0) return null;
  return Math.round((num / den) * 10000) / 10000;
}

function interpret(r: number | null): string {
  if (r === null) return "insufficient data";
  const abs = Math.abs(r);
  const dir = r > 0 ? "positive" : "negative";
  if (abs >= 0.7) return `strong ${dir} correlation`;
  if (abs >= 0.4) return `moderate ${dir} correlation`;
  if (abs >= 0.2) return `weak ${dir} correlation`;
  return "no meaningful correlation";
}

export async function execute(input: Input) {
  const { client_id, days } = input;

  const query = `
    SELECT
      cm.date::text AS date,
      COALESCE(SUM(cm.revenue), 0) AS revenue,
      COALESCE(SUM(cm.sessions), 0) AS sessions,
      COALESCE(SUM(cm.orders), 0) AS orders,
      CASE WHEN SUM(cm.sessions) > 0
        THEN ROUND(SUM(cm.orders)::numeric / SUM(cm.sessions) * 100, 4)
        ELSE 0 END AS conversion_rate,
      CASE WHEN SUM(cm.orders) > 0
        THEN ROUND(SUM(cm.revenue) / SUM(cm.orders), 2)
        ELSE 0 END AS aov,
      COALESCE(bm.traffic, 0) AS traffic,
      COALESCE(bm.rage_clicks, 0) AS rage_clicks,
      COALESCE(bm.dead_clicks, 0) AS dead_clicks,
      COALESCE(bm.friction_score, 0) AS friction_score,
      COALESCE(bm.script_errors, 0) AS script_errors,
      COALESCE(bm.engagement_time, 0) AS engagement_time
    FROM commerce_metrics cm
    LEFT JOIN behavioral_metrics bm
      ON bm.client_id = cm.client_id AND bm.date = cm.date
    WHERE cm.client_id = $1
      AND cm.date >= CURRENT_DATE - $2::int
    GROUP BY cm.date, bm.traffic, bm.rage_clicks, bm.dead_clicks,
             bm.friction_score, bm.script_errors, bm.engagement_time
    ORDER BY cm.date ASC
  `;

  const result = await pool.query(query, [client_id, days]);
  const rows: DayRow[] = result.rows.map((r) => ({
    date: r.date,
    revenue: Number(r.revenue),
    sessions: Number(r.sessions),
    orders: Number(r.orders),
    conversion_rate: Number(r.conversion_rate),
    aov: Number(r.aov),
    traffic: Number(r.traffic),
    rage_clicks: Number(r.rage_clicks),
    dead_clicks: Number(r.dead_clicks),
    friction_score: Number(r.friction_score),
    script_errors: Number(r.script_errors),
    engagement_time: Number(r.engagement_time),
  }));

  if (rows.length < 3) {
    return {
      client_id,
      days,
      data_points: rows.length,
      message:
        "Insufficient data points (need at least 3 days) to calculate meaningful correlations.",
      correlations: [],
    };
  }

  const pairs: { metricA: string; metricB: string; hypothesis: string }[] = [
    {
      metricA: "friction_score",
      metricB: "revenue",
      hypothesis: "Higher friction may reduce revenue",
    },
    {
      metricA: "traffic",
      metricB: "orders",
      hypothesis: "More traffic should drive more orders",
    },
    {
      metricA: "rage_clicks",
      metricB: "conversion_rate",
      hypothesis: "Rage clicks indicate frustration that hurts conversions",
    },
    {
      metricA: "script_errors",
      metricB: "revenue",
      hypothesis: "Script errors can block purchases and reduce revenue",
    },
    {
      metricA: "dead_clicks",
      metricB: "conversion_rate",
      hypothesis: "Dead clicks signal broken UI that hurts conversions",
    },
    {
      metricA: "engagement_time",
      metricB: "aov",
      hypothesis: "Higher engagement may correspond with higher order values",
    },
    {
      metricA: "sessions",
      metricB: "revenue",
      hypothesis: "More sessions should drive more revenue",
    },
  ];

  const correlations = pairs.map(({ metricA, metricB, hypothesis }) => {
    const a = rows.map((r) => r[metricA as keyof DayRow] as number);
    const b = rows.map((r) => r[metricB as keyof DayRow] as number);
    const r = pearson(a, b);
    return {
      metric_a: metricA,
      metric_b: metricB,
      hypothesis,
      correlation_coefficient: r,
      interpretation: interpret(r),
    };
  });

  // Sort by absolute correlation strength descending
  correlations.sort((a, b) => {
    const absA = Math.abs(a.correlation_coefficient ?? 0);
    const absB = Math.abs(b.correlation_coefficient ?? 0);
    return absB - absA;
  });

  return {
    client_id,
    days,
    data_points: rows.length,
    correlations,
  };
}
