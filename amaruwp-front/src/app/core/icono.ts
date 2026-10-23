import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { DomSanitizer } from '@angular/platform-browser';
import {
  ArrowRight, Bot, Building2, CalendarCheck, CalendarClock, Check, Clock, Headset, Layers, Leaf,
  LogOut, MapPin, MessageCircle, Package, Phone, Plus, Rocket, ShieldCheck, Smartphone, Sparkles,
  Users, X, Zap, type IconNode,
} from 'lucide';

const ICONOS: Record<string, IconNode> = {
  'arrow-right': ArrowRight, bot: Bot, 'building-2': Building2, 'calendar-check': CalendarCheck,
  'calendar-clock': CalendarClock, check: Check, clock: Clock, headset: Headset, layers: Layers,
  leaf: Leaf, 'log-out': LogOut, 'map-pin': MapPin, 'message-circle': MessageCircle, package: Package,
  phone: Phone, plus: Plus, rocket: Rocket, 'shield-check': ShieldCheck, smartphone: Smartphone,
  sparkles: Sparkles, users: Users, x: X, zap: Zap,
};

@Component({
  selector: 'app-icono',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[innerHTML]': 'svg()', style: 'display: inline-flex; line-height: 0' },
  template: '',
})
export class Icono {
  private sanitizer = inject(DomSanitizer);

  nombre = input.required<string>();
  tamano = input(20);

  svg = computed(() => {
    const nodos = ICONOS[this.nombre()] ?? [];
    const interior = nodos
      .map(([tag, attrs]) => `<${tag} ${Object.entries(attrs).map(([k, v]) => `${k}="${v}"`).join(' ')}/>`)
      .join('');
    const t = this.tamano();
    const html = `<svg xmlns="http://www.w3.org/2000/svg" width="${t}" height="${t}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${interior}</svg>`;
    return this.sanitizer.bypassSecurityTrustHtml(html);
  });
}
