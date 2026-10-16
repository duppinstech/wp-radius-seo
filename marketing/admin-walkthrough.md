# Radius SEO — setup walkthrough

Publish this page at https://duppinstech.com/radius-seo/guide/ . The Help button in the plugin opens that address. The illustrated PDF to upload beside it is `assets/manual/radius-seo-user-manual.pdf` in the plugin.

Screenshots in `marketing/screenshots/admin/` are the wp-admin screens, with red numbers. They are not in the plugin zip.

## Order of work

1. **Install.** Plugins → Add New → Upload Plugin. Activate. See `plugins.jpg`.
2. **License.** Radius → Settings → License. Paste the signed key and Save. Purchase is on the same screen. See `license.jpg`.
3. **Places.** Radius → Location library. Radius does not download cities. Upload a CSV with `name`, `lat`, and `lng`. Download CSV first if you want the column order: `id, name, slug, country, region, state, zip, lat, lng`. See `locations.jpg`.
4. **Service area.** Settings → Service areas. Pick an anchor place and a radius in miles. Save. Deploy stays off until this exists. See `settings-areas.jpg`.
5. **Template.** Templates → Add New Template. Write the page once. Use `{{place_name}}` and site tokens such as `{{phone-number}}`. See `templates.jpg` and `template-edit.jpg`.
6. **Deploy.** Deploy → the template card → Deploy & update all places. One page per place. Run it again after you edit the template. See `deploy.jpg` and `deploy-run.jpg`.

## What each screen is

| Screen | Screenshot | What the owner does there |
| --- | --- | --- |
| Dashboard | `dashboard.jpg` | Shortcuts. Templates, then the library, then Deploy. |
| Analytics | `analytics.jpg` | Visits and clicks after pages exist. Counts stay on the site. |
| Templates | `templates.jpg`, `template-edit.jpg` | The blueprint. Tokens and spintax live here. |
| Landings | `landings.jpg` | One row per deployed city page. |
| Service areas | `service-areas.jpg` | Hub pages, not the city landings. |
| Location library | `locations.jpg` | Import, export, and edit places. |
| Deploy | `deploy.jpg`, `deploy-run.jpg` | Publish or refresh pages from a finished template. |
| Import / export | `import.jpg` | Spintax, slots, places, and Magic Page migration. |
| Settings → General | `settings-general.jpg` | Hub URL prefix and deploy batch size. |
| Settings → Site replacers | `settings-replacers.jpg` | Company name, phone, and other tokens. |
| Settings → Content | `settings-content.jpg` | Leave rotation off unless spintax should change on a timer. |
| Settings → Database | `settings-database.jpg` | Leftover Magic Page options. Skip on a new site. |
| Settings → Integrations | `settings-integrations.jpg` | Elementor or Beaver Builder, then save, then deploy. |
| Logs | `logs.jpg` | The run, and the failure, after a server error. |
| Support | `support.jpg` | Email support@duppinstech.com. Nothing is stored on the site. |
| Help button | `help.jpg` | Bottom-right. Notes for this screen, the guide, and support. |

## Where place data comes from

Prepare a spreadsheet of the cities you serve. A row needs a name and coordinates. This is a valid first file:

```csv
name,slug,region,state,zip,lat,lng
Springfield,springfield,Virginia,VA,22150,38.7893,-77.1872
```

Upload it on Location library. Rows without coordinates are skipped at deploy.
