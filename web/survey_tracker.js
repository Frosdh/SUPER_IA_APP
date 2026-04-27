// survey_tracker.js
// Agrega seguimiento de inicio/fin de encuesta y eventos de tecla/selección.
(function(){
  const API = '/server_php/api_survey_event.php';
  let sessionId = null;

  function postEvent(eventType, payload={}){
    const body = { event_type: eventType, session_id: sessionId, payload };
    // si hay survey_id/asesor en el DOM, inclúyelos
    const surveyEl = document.getElementById('survey-form');
    if (surveyEl) body.survey_id = surveyEl.dataset.surveyId || surveyEl.getAttribute('data-survey-id');
    const asesorEl = document.getElementById('advisor-name');
    if (asesorEl) body.asesor_id = asesorEl.value || asesorEl.getAttribute('data-asesor-id');

    fetch(API, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) })
      .then(r => r.json()).then(j=>{
        if (j.session_id) sessionId = j.session_id;
      }).catch(()=>{});
  }

  // iniciar sesión cuando el asesor comience a escribir o esté seleccionado
  function initSurveyTracking(){
    const asesor = document.getElementById('advisor-name');
    const form = document.getElementById('survey-form');
    if (!form) return;

    let started = false;
    function startIfNeeded(){
      if (started) return; started = true;
      postEvent('survey_start', {info: 'started by advisor focus/typing'});
    }

    // focus/typing en campo asesor
    if (asesor){
      asesor.addEventListener('focus', startIfNeeded);
      asesor.addEventListener('input', startIfNeeded);
    }

    // track keypresses in survey inputs
    form.querySelectorAll('input, textarea, select').forEach(el => {
      el.addEventListener('keydown', () => postEvent('keypress'));
      el.addEventListener('change', () => postEvent('selection', {name: el.name, value: el.value}));
    });

    // on submit -> end session
    form.addEventListener('submit', (e)=>{
      // allow form submit to continue, but notify backend
      postEvent('survey_end');
      // optionally clear sessionId after a short delay
      setTimeout(()=>{ sessionId = null; }, 2000);
    });
  }

  // función para evaluar crédito desde frontend
  window.evaluateCredit = function(formSelector){
    const form = document.querySelector(formSelector);
    if (!form) return alert('Formulario no encontrado: ' + formSelector);
    const data = {};
    new FormData(form).forEach((v,k)=> data[k]=v);
    fetch('/server_php/api_evaluate_credit.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(data) })
      .then(r=>r.json()).then(j=>{
        if (j.status === 'success'){
          alert('Score: ' + j.score + '\nViable: ' + (j.viable ? 'SI' : 'NO'));
        } else alert('Error al evaluar crédito');
      }).catch(err=>{ console.error(err); alert('Error de red'); });
  }

  // iniciar cuando DOM listo
  document.addEventListener('DOMContentLoaded', initSurveyTracking);

})();
