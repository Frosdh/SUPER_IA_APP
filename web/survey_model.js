// survey_model.js
// Entrenador TF.js básico para predecir productividad a partir de sesiones.
// Requiere incluir: <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs"></script>

async function trainSurveyModel(fetchUrl){
  if (typeof tf === 'undefined') { console.error('TensorFlow.js no está cargado.'); return; }
  const res = await fetch(fetchUrl);
  const j = await res.json();
  if (j.status !== 'success') { console.error('No hay datos de entrenamiento'); return; }
  // Este endpoint debería devolver series de sesiones con: duration, keypress, selection, questions, productivity
  const rows = j.data.rows || j.data; // adaptar según endpoint
  if (!rows || rows.length < 10) { console.warn('Datos insuficientes para entrenar'); return; }

  const xs = rows.map(r => [r.duration || 0, r.keypress_count || 0, r.selection_count || 0, r.questions_total || 1]);
  const ys = rows.map(r => [r.productivity_score || 0]);

  const input = tf.tensor2d(xs);
  const labels = tf.tensor2d(ys);

  const model = tf.sequential();
  model.add(tf.layers.dense({units:32, activation:'relu', inputShape:[4]}));
  model.add(tf.layers.dense({units:16, activation:'relu'}));
  model.add(tf.layers.dense({units:1}));

  model.compile({optimizer: tf.train.adam(0.01), loss: 'meanSquaredError'});
  await model.fit(input, labels, {epochs: 50, batchSize: 16, validationSplit:0.1});

  await model.save('indexeddb://survey-productivity-model');
  console.log('Modelo entrenado y guardado en IndexedDB');
  input.dispose(); labels.dispose();
  return model;
}

async function predictProductivity(features){
  try{
    const model = await tf.loadLayersModel('indexeddb://survey-productivity-model');
    const t = tf.tensor2d([features]);
    const pred = model.predict(t);
    const v = (await pred.data())[0];
    t.dispose(); pred.dispose();
    return v;
  } catch (e){ console.error('No se encontró modelo entrenado', e); return null; }
}
