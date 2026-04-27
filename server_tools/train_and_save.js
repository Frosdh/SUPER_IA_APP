// Node script to train a TFJS model from JSON data and save it to disk (tfjs format)
// Usage: node train_and_save.js path/to/training_data.json

const fs = require('fs');
const tf = require('@tensorflow/tfjs-node');

async function main() {
  const p = process.argv[2];
  if (!p) { console.error('Usage: node train_and_save.js data.json'); process.exit(1); }
  if (!fs.existsSync(p)) { console.error('Data file not found:', p); process.exit(1); }
  const raw = JSON.parse(fs.readFileSync(p, 'utf8'));
  if (!Array.isArray(raw) || raw.length === 0) { console.error('No rows'); process.exit(1); }

  const xs = raw.map(r => [r.duration || 0, r.keypress_count || 0, r.selection_count || 0, r.questions_total || 1]);
  const ys = raw.map(r => [r.productivity_score || 0]);

  const input = tf.tensor2d(xs);
  const labels = tf.tensor2d(ys);

  const model = tf.sequential();
  model.add(tf.layers.dense({units:64, activation:'relu', inputShape:[4]}));
  model.add(tf.layers.dense({units:32, activation:'relu'}));
  model.add(tf.layers.dense({units:1}));

  model.compile({optimizer: tf.train.adam(0.01), loss: 'meanSquaredError'});
  await model.fit(input, labels, {epochs: 80, batchSize: 16, validationSplit:0.1});

  const outDir = 'models/survey-productivity';
  await model.save('file://' + outDir);
  console.log('Model saved to', outDir);
  input.dispose(); labels.dispose();
}

main().catch(e => { console.error(e); process.exit(1); });
