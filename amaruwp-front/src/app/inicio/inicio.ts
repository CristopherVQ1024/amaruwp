import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { Auth } from '../core/auth';
import { Icono } from '../core/icono';

@Component({
  selector: 'app-inicio',
  imports: [Icono],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './inicio.html',
  styleUrl: './inicio.scss',
})
export class Inicio {
  auth = inject(Auth);
}
