// Test client for the staff device protocol (decisions D-017). Node 18+.
//   node tools/device-client.mjs register "Front desk PC"
//   node tools/device-client.mjs status
//   node tools/device-client.mjs login <username> <password>
// DEV ONLY: the private key is saved in .device-state.json. Never commit it.
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';

const BASE = process.env.API_URL ?? 'http://127.0.0.1:8000/api/v1';
const STATE_FILE = process.env.DEVICE_STATE ?? '.device-state.json';
const PREFIX = 'recruitment-staff-login-v1';

async function api(method, path, body) {
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: res.status, json: await res.json().catch(() => ({})) };
}

const loadState = () => JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));

function firstMac() {
  const all = Object.values(os.networkInterfaces()).flat().filter(Boolean);
  return all.find((n) => !n.internal && n.mac && n.mac !== '00:00:00:00:00:00')?.mac ?? null;
}

async function register(label) {
  const { publicKey, privateKey } = crypto.generateKeyPairSync('ec', { namedCurve: 'prime256v1' });
  const publicPem = publicKey.export({ type: 'spki', format: 'pem' });
  const privatePem = privateKey.export({ type: 'pkcs8', format: 'pem' });

  const { status, json } = await api('POST', '/staff/devices/register', {
    public_key: publicPem,
    label: label ?? os.hostname(),
    mac_address: firstMac(),
    os_info: `${os.type()} ${os.release()}`,
  });
  if (status >= 300) return console.error(status, json);

  fs.writeFileSync(STATE_FILE, JSON.stringify({ deviceId: json.device.id, privatePem }, null, 2));
  console.log(`Device id:   ${json.device.id}`);
  console.log(`Fingerprint: ${json.device.fingerprint}   (read this to the admin)`);
  console.log(`Status:      ${json.device.status}`);
}

async function status() {
  const { deviceId } = loadState();
  console.log((await api('GET', `/staff/devices/${deviceId}/status`)).json);
}

async function login(username, password) {
  const { deviceId, privatePem } = loadState();
  const normalized = username.trim().toLowerCase();

  const challenge = (await api('POST', '/staff/auth/challenge', { device_id: deviceId })).json;

  const payload = [PREFIX, challenge.challenge_id, challenge.challenge, deviceId, normalized].join('\n');
  // For EC keys Node's default signature encoding is DER, which is what the server expects.
  const signature = crypto
    .sign('sha256', Buffer.from(payload, 'utf8'), crypto.createPrivateKey(privatePem))
    .toString('base64');

  const { status: code, json } = await api('POST', '/staff/auth/login', {
    device_id: deviceId,
    challenge_id: challenge.challenge_id,
    signature,
    username: normalized,
    password,
  });
  console.log(code, code === 200 ? { token: json.token, user: json.user.username } : json);
}

const [command, ...args] = process.argv.slice(2);
const commands = { register, status, login };
if (!commands[command]) {
  console.error('Usage: register [label] | status | login <username> <password>');
  process.exit(1);
}
await commands[command](...args);
