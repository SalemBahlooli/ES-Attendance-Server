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

## Viewing attendance

The **Attendance** tab shows any period, not only one day:

- Quick periods: Today, Yesterday, This week, Last 7 days, This month, Last month, Last 30 days, or any From/To dates. The ‹ › arrows move to the previous/next period.
- The calendar shades the days that have punches (darker = more employees). Click a day to open it, or switch to "Pick a range" and click the first and last day.
- Filter by employee and by device. The filters stay in the page URL, so you can bookmark or share a view.
- Charts: attendance per day (on time / late), a timeline of the day, arrival times, punches by hour, and an attendance map (employee × day).
- Tables: by employee (days present, late, average in/out, hours), day by day, or every punch. Export to Excel uses the same filters.

## Importing data from USB, a file, or the LAN

Open the **Import Data** tab.

- **USB / file**: on the device go to **Menu → PenDrive Mng (or USB Manager) → Download AttLog**, then choose the file (`1_attlog.dat` or `<serial>_attlog.dat`). CSV/TXT files with an employee ID column and a date/time column also work. You see a preview (how many punches, how many are new) before anything is saved.
- **LAN**: enter the device IP (port `4370`) and click "Pull punches now". This works only when the server is on the same network as the device.
- **LAN bridge** (server on Railway): on any office PC on the same network as the device, install Node.js, run `npm install` in this repository, then:

  ```
  node tools/lan-bridge.js --device 192.168.1.201 --server https://your-app.up.railway.app --password YOUR_DASHBOARD_PASSWORD --every 5
  ```

  It reads the device over the LAN every 5 minutes and sends new punches to the dashboard.

Punches already saved (same employee, same second) are skipped, whatever the source.

## Notes

- Employee names: click "Pull employee names from device" in the Devices tab, or type them manually in the Employees tab.
- Old punches stored on the device: click "Re-pull all punches". Duplicate punches are ignored automatically.
