# Forge control-plane deployment

TPF-26 moves the Forge control plane out of the disposable DevForge agent VM. Forge stays available while that VM is stopped and starts it only when an approved Development Request needs an agent.

## Runtime topology

One immutable Forge image supports four explicit runtime roles:

| Role | Purpose | Execution model |
| --- | --- | --- |
| `web` | Human UI and authenticated worker API | Cloud Run service |
| `queue` | Drain durable Laravel jobs | Cloud Run job, scheduled once per minute |
| `scheduler` | Run Laravel's due schedules | Cloud Run job, scheduled once per minute |
| `migrate` | Apply database migrations | Cloud Run job, invoked during a reviewed release |

All roles share one isolated PostgreSQL database. The pilot deliberately uses Laravel's database cache, session, and queue drivers so Redis is not a deployment dependency.

The image includes a pinned Cloud SQL Auth Proxy. When `CLOUD_SQL_CONNECTION_NAME` is present, the entrypoint starts it with Private Service Connect, waits for the local socket, and stops it when a short-lived job exits. Authentication comes from the attached runtime service account; no service-account key is stored in the image or environment.

The queue job is bounded by `FORGE_QUEUE_MAX_TIME_SECONDS` and exits when the queue is empty. That prevents overlap from creating a permanently running worker while preserving Laravel's database job locks.

## Trust boundaries

- The Forge web service uses its own runtime service account.
- The DevForge VM uses its existing worker service account and receives no static credentials.
- Worker API calls require a Google-issued identity token with the configured audience and exact mapped VM service-account identity.
- Only the DevForge VM service account receives Cloud Run invoker access to the worker API route.
- The Forge runtime identity receives only logging, monitoring, Secret Manager access to Forge secrets, Cloud SQL client access, and the existing narrow VM lifecycle permissions.
- PostgreSQL, secrets, and queue state are not shared with LiteLLM.

## Release order

1. Build and scan the image from the reviewed Forge commit. Submit `cloudbuild.yaml` with `_TAG` set to the 7–40 character lowercase Git commit SHA; mutable tags such as `latest` are rejected by the Terraform contract.
2. Publish the image under an immutable commit tag.
3. Create or update the isolated PostgreSQL instance and secrets.
4. Execute the `migrate` job and require a successful exit.
5. Roll the web service, queue job, and scheduler job to the same image digest.
6. Verify `/up`, authenticated admin access, and a read-only pilot Development Request.
7. Only after the control plane passes those checks, roll the DevForge VM to the worker-capable image and activate its non-secret metadata.

Terraform must be planned and reviewed before any apply. Replacing a VM image, changing IAM, enabling orchestration, running migrations against shared data, and switching traffic are separate approval points.
