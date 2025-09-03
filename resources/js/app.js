import './bootstrap';
import { createApp } from 'vue';
import SurveyFlowBuilder from './components/SurveyFlowBuilder.vue';
import Toast from 'vue-toastification';
import 'vue-toastification/dist/index.css';

if (document.getElementById('survey-flow-app')) {

    const app = createApp(SurveyFlowBuilder, {
        surveyId: window.surveyId
    })

    app.use(Toast, {
        position: 'top-right',
        timeout: 5000,
      });


    app.mount('#survey-flow-app');

}