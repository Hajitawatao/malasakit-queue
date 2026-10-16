<!--
  Layout ng printout para sa 80mm thermal printer.
  Nakatago sa screen; lalabas lang kapag window.print().
-->
<template>
  <div v-if="ticket" class="ticket-print">
    <div class="hosp">{{ hospital.name }}</div>
    <div class="addr">{{ hospital.address }}</div>
    <div class="addr">{{ hospital.phone }}</div>
    <div class="service">{{ ticket.service.name }}</div>
    <div class="code">{{ ticket.code }}</div>
    <div class="lane">{{ ticket.lane_label }}</div>
    <div class="meta">{{ ticket.issued_at }}</div>
    <div class="meta">Nauuna sa inyo: {{ ticket.ahead }}</div>
    <div class="foot">Hintayin po ang pagtawag ng inyong numero.</div>
  </div>
</template>

<script setup>
defineProps({ ticket: Object, hospital: Object })
</script>

<style>
.ticket-print { display: none; }

@media print {
  @page { size: 80mm auto; margin: 0; }
  body * { visibility: hidden; }
  .ticket-print, .ticket-print * { visibility: visible; }
  .ticket-print {
    display: block;
    position: absolute; left: 0; top: 0;
    width: 72mm; padding: 4mm;
    font-family: Arial, sans-serif; text-align: center; color: #000;
  }
  .ticket-print .hosp { font-weight: 700; font-size: 13pt; letter-spacing: .5px; }
  .ticket-print .addr { font-size: 8.5pt; }
  .ticket-print .service { font-size: 20pt; font-weight: 700; margin-top: 3mm; }
  .ticket-print .code { font-size: 60pt; font-weight: 900; line-height: 1; margin: 1mm 0; }
  .ticket-print .lane { font-size: 13pt; font-weight: 700; text-transform: uppercase; }
  .ticket-print .meta { font-size: 9pt; margin-top: 1mm; }
  .ticket-print .foot { font-size: 9pt; margin-top: 3mm; border-top: 1px dashed #000; padding-top: 2mm; }
}
</style>
