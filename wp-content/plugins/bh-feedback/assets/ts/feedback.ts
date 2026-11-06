// Timestamped waveform annotations. Decodes the track client-side (Web
// Audio API) only to draw a peaks waveform. BHFData carries the ajax URL and
// nonce; each .bhf-waveform carries its request id, audio url, canMark flag,
// and initial annotation tree as JSON data attributes.

interface BHFAnnotation {
  id: number;
  parent_id: number;
  user_id: number;
  timestamp_seconds: string | null;
  body: string;
  created_at: string;
  replies: BHFAnnotation[];
}

interface BHFDataShape {
  ajaxUrl: string;
  nonce: string;
}

declare const BHFData: BHFDataShape;

function formatTime(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return m + ':' + String(s).padStart(2, '0');
}

function drawWaveform(canvas: HTMLCanvasElement, peaks: number[]): void {
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  const width = canvas.width;
  const height = canvas.height;
  ctx.clearRect(0, 0, width, height);
  const barWidth = width / peaks.length;
  const mid = height / 2;
  ctx.fillStyle = getComputedStyle(canvas).getPropertyValue('--bhf-waveform-color') || '#8a8a8a';
  peaks.forEach((peak, i) => {
    const barHeight = Math.max(1, peak * mid);
    ctx.fillRect(i * barWidth, mid - barHeight, Math.max(1, barWidth - 1), barHeight * 2);
  });
}

function computePeaks(buffer: AudioBuffer, count: number): number[] {
  const data = buffer.getChannelData(0);
  const blockSize = Math.floor(data.length / count) || 1;
  const peaks: number[] = [];
  for (let i = 0; i < count; i++) {
    const start = i * blockSize;
    let max = 0;
    for (let j = 0; j < blockSize && start + j < data.length; j++) {
      const abs = Math.abs(data[start + j] ?? 0);
      if (abs > max) max = abs;
    }
    peaks.push(max);
  }
  return peaks;
}

function renderMarkers(root: HTMLElement, annotations: BHFAnnotation[], duration: number, requestId: number, canMark: boolean): void {
  const overlay = root.querySelector<HTMLElement>('.bhf-waveform-markers');
  const thread = root.querySelector<HTMLElement>('.bhf-annotation-thread');
  if (!overlay || !thread) return;

  overlay.innerHTML = '';
  annotations.forEach((marker) => {
    const ts = parseFloat(marker.timestamp_seconds ?? '0');
    const pct = duration > 0 ? (ts / duration) * 100 : 0;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'bhf-waveform-marker';
    btn.style.left = pct + '%';
    btn.title = formatTime(ts) + ' — ' + marker.body.slice(0, 60);
    btn.addEventListener('click', () => renderThread(thread, marker, requestId));
    overlay.appendChild(btn);
  });
}

function renderThread(thread: HTMLElement, marker: BHFAnnotation, requestId: number): void {
  const ts = formatTime(parseFloat(marker.timestamp_seconds ?? '0'));
  thread.innerHTML = '';
  thread.classList.add('bhf-annotation-thread--open');

  const heading = document.createElement('p');
  heading.className = 'bhf-annotation-thread-heading';
  heading.textContent = 'At ' + ts;
  thread.appendChild(heading);

  const body = document.createElement('p');
  body.className = 'bhf-annotation-body';
  body.textContent = marker.body;
  thread.appendChild(body);

  marker.replies.forEach((reply) => {
    const replyEl = document.createElement('p');
    replyEl.className = 'bhf-annotation-reply';
    replyEl.textContent = reply.body;
    thread.appendChild(replyEl);
  });

  const form = document.createElement('form');
  form.className = 'bhf-annotation-reply-form';
  const textarea = document.createElement('textarea');
  textarea.rows = 2;
  textarea.placeholder = 'Reply...';
  const submit = document.createElement('button');
  submit.type = 'submit';
  submit.textContent = 'Reply';
  form.appendChild(textarea);
  form.appendChild(submit);
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = textarea.value.trim();
    if (!text) return;
    submitAnnotation(requestId, marker.id, null, text).then((result) => {
      if (result) {
        marker.replies.push(result);
        renderThread(thread, marker, requestId);
      }
    });
  });
  thread.appendChild(form);
}

function submitAnnotation(requestId: number, parentId: number, timestampSeconds: number | null, body: string): Promise<BHFAnnotation | null> {
  const params = new URLSearchParams();
  params.set('action', 'bhf_add_annotation');
  params.set('nonce', BHFData.nonce);
  params.set('request_id', String(requestId));
  params.set('parent_id', String(parentId));
  if (timestampSeconds !== null) params.set('timestamp_seconds', String(timestampSeconds));
  params.set('body', body);

  return fetch(BHFData.ajaxUrl, { method: 'POST', body: params, credentials: 'same-origin' })
    .then((r) => r.json())
    .then((json: { success: boolean; data?: { id: number; message?: string } }) => {
      if (!json.success) {
        window.alert((json.data && json.data.message) || 'Could not save that note.');
        return null;
      }
      return {
        id: json.data!.id, parent_id: parentId, user_id: 0,
        timestamp_seconds: timestampSeconds !== null ? String(timestampSeconds) : null,
        body, created_at: '', replies: [],
      };
    })
    .catch(() => {
      window.alert('Could not save that note — check your connection and try again.');
      return null;
    });
}

function initWaveform(root: HTMLElement): void {
  const requestId = parseInt(root.dataset.requestId ?? '0', 10);
  const audioUrl = root.dataset.audioUrl ?? '';
  const canMark = root.dataset.canMark === '1';
  let annotations: BHFAnnotation[] = [];
  try {
    annotations = JSON.parse(root.dataset.annotations ?? '[]') as BHFAnnotation[];
  } catch (e) {
    annotations = [];
  }

  const canvas = root.querySelector<HTMLCanvasElement>('.bhf-waveform-canvas');
  if (!canvas || !requestId || !audioUrl) return;

  const AudioContextCtor = window.AudioContext || (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
  if (!AudioContextCtor) return; // no Web Audio API; the plain <audio> player still works

  fetch(audioUrl)
    .then((r) => r.arrayBuffer())
    .then((buf) => new AudioContextCtor().decodeAudioData(buf))
    .then((decoded) => {
      const peaks = computePeaks(decoded, 300);
      drawWaveform(canvas, peaks);
      renderMarkers(root, annotations, decoded.duration, requestId, canMark);

      if (canMark) {
        canvas.addEventListener('click', (e) => {
          const rect = canvas.getBoundingClientRect();
          const fraction = (e.clientX - rect.left) / rect.width;
          const timestamp = Math.max(0, fraction * decoded.duration);
          const body = window.prompt('Note at ' + formatTime(timestamp) + ':');
          if (!body || !body.trim()) return;
          submitAnnotation(requestId, 0, timestamp, body.trim()).then((result) => {
            if (result) {
              annotations = annotations.concat([result]);
              renderMarkers(root, annotations, decoded.duration, requestId, canMark);
            }
          });
        });
        canvas.classList.add('bhf-waveform-canvas--clickable');
      }
    })
    .catch(() => {
      // Decoding failed; leave the canvas blank. The <audio> element is the fallback.
    });
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll<HTMLElement>('.bhf-waveform').forEach(initWaveform);
});
