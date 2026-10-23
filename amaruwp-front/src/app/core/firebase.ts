import { initializeApp } from 'firebase/app';
import { getAuth } from 'firebase/auth';
import { environment } from '../../environments/environment';

export const firebaseConfigurado = !environment.firebase.apiKey.startsWith('PEGAR');

export const firebaseAuth = firebaseConfigurado ? getAuth(initializeApp(environment.firebase)) : null;
