<?php
/**
 * ping_ml.php — one-page integration tester for all ML endpoints
 * - Shows current ML_BASE + masked ML_KEY
 * - Calls: /version, /health, /predict, /price_interval, /recommend, /comps, /loc_score
 * - Lets you override default JSON payloads via the form at the bottom
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/ml_client.php';

function pretty($title, $data) {
    echo "<h2 style='margin:18px 0 8px'>{$title}</h2>";
    echo "<pre style='white-space:pre-wrap;background:#111;color:#eee;padding:12px;border-radius:8px'>"
       . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
       . "</pre>";
}

function mask_key($k) {
    $k = (string)$k;
    return strlen($k) >= 8 ? substr($k,0,4) . '...' . substr($k,-4) : '(short)';
}

/* ------------------ defaults ------------------ */
$default_inputs = [[
  'Capacity'=>3,'Bedroom'=>1,'unit_sqm'=>24,'cap_per_bedroom'=>3,
  'Type'=>'Apartment','Kitchen'=>'Yes','Kitchen type'=>'Shared',
  'Gender specific'=>'Mixed','Pets'=>'Allowed','Location'=>'Makati'
]];

$default_listings = [
  ['id'=>1,'Capacity'=>2,'Bedroom'=>1,'unit_sqm'=>20,'cap_per_bedroom'=>2,'Type'=>'Condo','Kitchen'=>'Yes','Kitchen type'=>'Private','Gender specific'=>'Mixed','Pets'=>'Allowed','Location'=>'Quezon City','dist_school_km'=>0.5,'dist_work_km'=>1.2],
  ['id'=>2,'Capacity'=>3,'Bedroom'=>2,'unit_sqm'=>35,'cap_per_bedroom'=>1.5,'Type'=>'Apartment','Kitchen'=>'Yes','Kitchen type'=>'Shared','Gender specific'=>'Mixed','Pets'=>'Not allowed','Location'=>'Makati','dist_school_km'=>1.0,'dist_work_km'=>0.8],
  ['id'=>3,'Capacity'=>4,'Bedroom'=>2,'unit_sqm'=>40,'cap_per_bedroom'=>2,'Type'=>'Apartment','Kitchen'=>'Yes','Kitchen type'=>'Private','Gender specific'=>'Mixed','Pets'=>'Allowed','Location'=>'Pasig','dist_school_km'=>2.1,'dist_work_km'=>3.3],
];
$default_user_pref = ['budget'=>15000];
$default_noise = 0.08;
$default_top_k = 5;
$default_k_comps = 3;

/* ------------------ accept overrides from form (POST) ------------------ */
$inputs      = isset($_POST['inputs'])      && $_POST['inputs']      !== '' ? json_decode($_POST['inputs'], true)      : $default_inputs;
$listings    = isset($_POST['listings'])    && $_POST['listings']    !== '' ? json_decode($_POST['listings'], true)    : $default_listings;
$user_pref   = isset($_POST['user_pref'])   && $_POST['user_pref']   !== '' ? json_decode($_POST['user_pref'], true)   : $default_user_pref;
$noise       = isset($_POST['noise'])       && $_POST['noise']       !== '' ? floatval($_POST['noise'])                : $default_noise;
$top_k       = isset($_POST['top_k'])       && $_POST['top_k']       !== '' ? intval($_POST['top_k'])                  : $default_top_k;
$k_comps     = isset($_POST['k_comps'])     && $_POST['k_comps']     !== '' ? intval($_POST['k_comps'])                : $default_k_comps;

// basic validation fallbacks if JSON decode fails
if ($inputs === null)    $inputs = $default_inputs;
if ($listings === null)  $listings = $default_listings;
if ($user_pref === null) $user_pref = $default_user_pref;

/* ------------------ run calls ------------------ */
$base_info = [
  'using_base' => rtrim(ML_BASE, '/'),
  'key_len'    => strlen(ML_KEY),
  'key_masked' => mask_key(ML_KEY),
];

$results = [
  'version'        => ml_version(),
  'health'         => ml_health(),
  'predict'        => ml_predict($inputs),
  'price_interval' => ml_price_interval($inputs, $noise),
  'recommend'      => ml_recommend($listings, $user_pref, $top_k),
  'comps'          => ml_comps($listings[0], $listings, $k_comps),
  'loc_score'      => ml_loc_score([[
                        'dist_school_km' => $listings[0]['dist_school_km'] ?? 1.0,
                        'dist_work_km'   => $listings[0]['dist_work_km']   ?? 1.5,
                        'rooms_score'    => 0.6,
                        'safety_index'   => 0.7,
                      ]]),
];

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>HanapBahay ML — One-Page Tester</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<style>
  body{font:14px/1.4 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:#0b0e13; color:#e6e6e6; margin:0; padding:24px;}
  h1{margin:0 0 8px; font-size:20px}
  .grid{display:grid; gap:16px; grid-template-columns:1fr}
  @media(min-width:1100px){ .grid{grid-template-columns:1fr 1fr;} }
  .card{background:#121722; border:1px solid #1c2333; border-radius:12px; padding:16px;}
  textarea{width:100%; height:140px; background:#0f141d; color:#e6e6e6; border:1px solid #243049; border-radius:8px; padding:10px; font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;}
  input[type="number"], input[type="text"]{width:100%; background:#0f141d; color:#e6e6e6; border:1px solid #243049; border-radius:8px; padding:8px;}
  .btn{background:#2d5bff; color:#fff; border:none; border-radius:8px; padding:10px 14px; cursor:pointer;}
  .btn:hover{opacity:.9}
  pre{overflow:auto; max-height:420px;}
  a{color:#7aa2ff}
</style>
</head>
<body>
  <h1>HanapBahay ML — One-Page Tester</h1>

  <div class="grid">
    <div class="card">
      <h2>Environment</h2>
      <pre><?php echo htmlspecialchars(json_encode($base_info, JSON_PRETTY_PRINT)); ?></pre>
      <p>Make sure these match the **latest** values printed by your Colab Cell C.</p>
    </div>

    <div class="card">
      <h2>Endpoints status</h2>
      <?php
        pretty('/version',        $results['version']);
        pretty('/health',         $results['health']);
      ?>
    </div>

    <div class="card">
      <?php pretty('/predict',        $results['predict']); ?>
    </div>

    <div class="card">
      <?php pretty('/price_interval', $results['price_interval']); ?>
    </div>

    <div class="card">
      <?php pretty('/recommend',      $results['recommend']); ?>
    </div>

    <div class="card">
      <?php pretty('/comps',          $results['comps']); ?>
    </div>

    <div class="card">
      <?php pretty('/loc_score',      $results['loc_score']); ?>
    </div>

    <div class="card" style="grid-column:1 / -1">
      <h2>Override payloads (optional)</h2>
      <form method="post">
        <div style="display:grid; gap:16px; grid-template-columns:1fr 1fr 1fr;">
          <div>
            <label>inputs (for /predict, /price_interval)</label>
            <textarea name="inputs"><?php echo htmlspecialchars(json_encode($inputs, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></textarea>
          </div>
          <div>
            <label>listings (for /recommend, /comps)</label>
            <textarea name="listings"><?php echo htmlspecialchars(json_encode($listings, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></textarea>
          </div>
          <div>
            <label>user_pref (for /recommend)</label>
            <textarea name="user_pref"><?php echo htmlspecialchars(json_encode($user_pref, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); ?></textarea>
          </div>
        </div>

        <div style="display:grid; gap:16px; grid-template-columns:repeat(3, 1fr); margin-top:12px;">
          <div>
            <label>noise (price_interval)</label>
            <input type="number" step="0.01" name="noise" value="<?php echo htmlspecialchars($noise); ?>"/>
          </div>
          <div>
            <label>top_k (recommend)</label>
            <input type="number" name="top_k" value="<?php echo htmlspecialchars($top_k); ?>"/>
          </div>
          <div>
            <label>k (comps)</label>
            <input type="number" name="k_comps" value="<?php echo htmlspecialchars($k_comps); ?>"/>
          </div>
        </div>

        <div style="margin-top:14px">
          <button class="btn" type="submit">Run again with these payloads</button>
        </div>
      </form>
      <p style="opacity:.8;margin-top:10px">Tip: keep JSON keys exactly as listed in <code>/version.features</code>.</p>
    </div>
  </div>

  <p style="opacity:.7;margin-top:18px">If any call fails with 401, re-check that your Colab’s printed API key is in <code>includes/config.php</code>. If calls time out or return 1033/5xx, the Cloudflare tunnel likely expired; restart Cell C in Colab and update the BASE + KEY here.</p>
</body>
</html>