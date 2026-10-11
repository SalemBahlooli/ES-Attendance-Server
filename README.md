# ES Attendance Server

An attendance server that receives punches directly from a **ZKTeco uFace 800** device via its Cloud Server (ADMS) feature, stores them in PostgreSQL, and shows them in a web dashboard.

## Deploying on Railway

1. In your Railway project: **New → GitHub Repository** and pick this repository.
2. If you don't have a database yet: **New → Database → PostgreSQL**.
3. In the server service → **Variables**, add:

   | Variable | Value |
   |---|---|
   | `DATABASE_URL` | `${{Postgres.DATABASE_URL}}` |
   | `ADMIN_PASSWORD` | Dashboard password |
   | `SESSION_SECRET` | A long random string |
   | `WORK_START` | Work start time, e.g. `08:00` (optional) |

4. **Settings → Networking**:
   - **Generate Domain**: the dashboard URL you open in the browser.
   - **TCP Proxy** on port `8080` (or the value of `PORT`): the address you enter on the device.

## Device setup

**Menu → COMM → Cloud Server Setting**

- Enable Domain Name: **ON**
- Server Address: the TCP Proxy address without the port (e.g. `xxx.proxy.rlwy.net`)
- Server Port: the TCP Proxy port (e.g. `12345`)
- HTTPS: **OFF**
- Proxy Server: **OFF**

Save and restart the device. Within a minute it shows up in the **Devices** tab of the dashboard.

## Notes

- Employee names: click "Pull employee names from device" in the Devices tab, or type them manually in the Employees tab.
- Old punches stored on the device: click "Re-pull all punches". Duplicate punches are ignored automatically.
