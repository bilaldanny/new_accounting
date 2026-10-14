<?php

namespace App\Services\Documents\TemplateCode;

/**
 * Starting points for the template code editor. Each is plain HTML + CSS with placeholders; docs/invoice-template-codes.md
 * is generated from the same list (see the test), so what the editor offers and what the document says cannot drift apart.
 */
final class ReadyMadeTemplates
{
    /**
     * @return list<array{key: string, name: string, doc_type: string, paper: string, html: string, css: string}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'a4-classic', 'name' => 'A4 classic', 'doc_type' => 'invoice', 'paper' => 'a4',
                'html' => <<<'HTML'
<div class="page">
  <table class="head"><tr>
    <td><img src="{{company.logo}}" alt="" class="logo"><h1>{{company.name}}</h1><p>{{company.address}}<br>{{company.phone}} {{company.email}}</p><p>NTN {{company.ntn}} &nbsp; STRN {{company.strn}}</p></td>
    <td class="r"><h2>INVOICE</h2><p>No. <b>{{invoice.number}}</b><br>Date {{invoice.date}}<br>Due {{invoice.due_date}}</p></td>
  </tr></table>
  <div class="party"><b>Bill to</b><br>{{customer.name}}<br>{{customer.address}}<br>{{customer.phone}}</div>
  <table class="lines"><thead><tr><th>#</th><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Discount %</th><th class="r">Total</th></tr></thead>
  <tbody>{{#lines}}<tr><td>{{sr}}</td><td>{{name}}</td><td class="r">{{qty}} {{unit}}</td><td class="r">{{price}}</td><td class="r">{{discount}}</td><td class="r">{{total}}</td></tr>{{/lines}}</tbody></table>
  <table class="totals">
    <tr><td>Subtotal</td><td class="r">{{totals.subtotal}}</td></tr>
    <tr><td>Discount</td><td class="r">{{totals.discount}}</td></tr>
    <tr><td>Tax</td><td class="r">{{totals.tax}}</td></tr>
    <tr class="grand"><td>Total</td><td class="r">{{totals.grand}}</td></tr>
    <tr><td>Paid</td><td class="r">{{totals.paid}}</td></tr>
    <tr><td>Balance</td><td class="r">{{totals.balance}}</td></tr>
  </table>
  <p class="note">{{terms}}</p>
  <p class="foot">{{footer_note}}</p>
</div>
HTML,
                'css' => <<<'CSS'
@page { size: A4; margin: 12mm; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #1b1b1b; margin: 0; }
.page { padding: 4mm; }
table { width: 100%; border-collapse: collapse; }
.head td { vertical-align: top; }
.logo { max-height: 18mm; }
h1 { font-size: 16pt; margin: 2mm 0; }
h2 { font-size: 20pt; margin: 0; letter-spacing: 2px; }
.r { text-align: right; }
.party { margin: 6mm 0; padding: 3mm; border: 1px solid #ccc; }
.lines th { background: #f0f0f0; text-align: left; padding: 2mm; border-bottom: 2px solid #333; }
.lines td { padding: 2mm; border-bottom: 1px solid #ddd; }
.totals { width: 70mm; margin: 6mm 0 0 auto; }
.totals td { padding: 1.5mm 2mm; }
.grand td { font-weight: bold; border-top: 2px solid #333; }
.note { font-size: 9pt; color: #555; margin-top: 8mm; }
.foot { text-align: center; font-size: 9pt; }
CSS,
            ],
            [
                'key' => 'a4-modern', 'name' => 'A4 modern', 'doc_type' => 'invoice', 'paper' => 'a4',
                'html' => <<<'HTML'
<div class="page">
  <div class="band"><div><img src="{{company.logo}}" alt="" class="logo"><div class="co">{{company.name}}</div></div><div class="inv">Invoice<br><span>{{invoice.number}}</span></div></div>
  <div class="cols">
    <div><small>Billed to</small><p><b>{{customer.name}}</b><br>{{customer.address}}<br>{{customer.email}}</p></div>
    <div><small>Date</small><p>{{invoice.date}}</p></div>
    <div><small>Due</small><p>{{invoice.due_date}}</p></div>
    <div><small>Amount due</small><p class="due">{{totals.balance}}</p></div>
  </div>
  <table class="lines"><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Amount</th></tr></thead>
  <tbody>{{#lines}}<tr><td>{{name}}<br><small>{{sku}}</small></td><td class="r">{{qty}}</td><td class="r">{{price}}</td><td class="r">{{total}}</td></tr>{{/lines}}</tbody></table>
  <div class="sum"><div><span>Subtotal</span><span>{{totals.subtotal}}</span></div><div><span>Tax</span><span>{{totals.tax}}</span></div><div class="g"><span>Total</span><span>{{totals.grand}}</span></div></div>
  <p class="foot">{{footer_note}}</p><img src="{{qr}}" alt="" class="qr">
</div>
HTML,
                'css' => <<<'CSS'
@page { size: A4; margin: 0; }
body { font-family: Verdana, Arial, sans-serif; font-size: 10.5pt; color: #222; margin: 0; }
.page { padding: 0 14mm 14mm; }
.band { background: #0f766e; color: #fff; margin: 0 -14mm 8mm; padding: 10mm 14mm; display: flex; justify-content: space-between; align-items: center; }
.logo { max-height: 14mm; background: #fff; padding: 1mm; }
.co { font-size: 15pt; font-weight: bold; }
.inv { font-size: 22pt; text-align: right; }
.inv span { font-size: 12pt; }
.cols { display: flex; justify-content: space-between; margin-bottom: 8mm; }
small { color: #777; text-transform: uppercase; font-size: 7.5pt; }
.due { font-size: 15pt; font-weight: bold; color: #0f766e; }
table { width: 100%; border-collapse: collapse; }
.lines th { text-align: left; color: #0f766e; border-bottom: 2px solid #0f766e; padding: 2mm; }
.lines td { padding: 2.5mm 2mm; border-bottom: 1px solid #e5e5e5; }
.r { text-align: right; }
.sum { width: 70mm; margin: 6mm 0 0 auto; }
.sum div { display: flex; justify-content: space-between; padding: 1.5mm 0; }
.sum .g { border-top: 2px solid #0f766e; font-weight: bold; font-size: 12pt; }
.foot { text-align: center; color: #666; margin-top: 12mm; }
.qr { width: 22mm; height: 22mm; }
CSS,
            ],
            [
                'key' => 'thermal-80', 'name' => 'Thermal 80 mm', 'doc_type' => 'invoice', 'paper' => 'thermal80',
                'html' => <<<'HTML'
<div class="t">
  <div class="c"><b>{{company.name}}</b><br>{{company.address}}<br>{{company.phone}}</div>
  <hr>
  <div>Inv: {{invoice.number}}<br>{{invoice.date}}</div>
  <hr>
  <table>{{#lines}}<tr><td colspan="2">{{name}}</td></tr><tr><td>{{qty}} x {{price}}</td><td class="r">{{total}}</td></tr>{{/lines}}</table>
  <hr>
  <table><tr><td>Total</td><td class="r"><b>{{totals.grand}}</b></td></tr><tr><td>Paid</td><td class="r">{{totals.paid}}</td></tr><tr><td>Balance</td><td class="r">{{totals.balance}}</td></tr></table>
  <hr>
  <div class="c">{{footer_note}}</div>
  <div class="c"><img src="{{qr}}" alt="" width="90" height="90"></div>
</div>
HTML,
                'css' => <<<'CSS'
@page { size: 80mm auto; margin: 2mm; }
body { font-family: "Courier New", monospace; font-size: 9pt; width: 72mm; margin: 0; color: #000; }
table { width: 100%; border-collapse: collapse; }
.r { text-align: right; }
.c { text-align: center; }
hr { border: 0; border-top: 1px dashed #000; }
CSS,
            ],
            [
                'key' => 'quotation', 'name' => 'Quotation', 'doc_type' => 'quotation', 'paper' => 'a4',
                'html' => <<<'HTML'
<div class="page">
  <h1>QUOTATION</h1>
  <table class="head"><tr><td><b>{{company.name}}</b><br>{{company.address}}<br>{{company.phone}}</td><td class="r">Quote no. <b>{{invoice.number}}</b><br>Date {{invoice.date}}<br>Valid until {{invoice.due_date}}</td></tr></table>
  <p class="to">Prepared for<br><b>{{customer.name}}</b><br>{{customer.address}}</p>
  <table class="lines"><thead><tr><th>#</th><th>Description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead>
  <tbody>{{#lines}}<tr><td>{{sr}}</td><td>{{name}}</td><td class="r">{{qty}} {{unit}}</td><td class="r">{{price}}</td><td class="r">{{total}}</td></tr>{{/lines}}</tbody></table>
  <p class="tot">Total: <b>{{totals.grand}}</b></p>
  <h3>Terms</h3><p>{{terms}}</p>
  <div class="sign">Authorised signature</div>
</div>
HTML,
                'css' => <<<'CSS'
@page { size: A4; margin: 15mm; }
body { font-family: Georgia, "Times New Roman", serif; font-size: 11pt; color: #222; }
h1 { letter-spacing: 4px; border-bottom: 3px double #444; padding-bottom: 3mm; }
table { width: 100%; border-collapse: collapse; }
.r { text-align: right; }
.to { margin: 8mm 0; }
.lines th { border-top: 1px solid #444; border-bottom: 1px solid #444; padding: 2mm; text-align: left; }
.lines td { padding: 2mm; border-bottom: 1px dotted #aaa; }
.tot { text-align: right; font-size: 13pt; margin-top: 6mm; }
.sign { margin-top: 25mm; width: 60mm; border-top: 1px solid #444; padding-top: 2mm; }
CSS,
            ],
            [
                'key' => 'receipt', 'name' => 'Receipt', 'doc_type' => 'receipt', 'paper' => 'a5',
                'html' => <<<'HTML'
<div class="page">
  <table class="head"><tr><td><img src="{{company.logo}}" alt="" class="logo"></td><td class="r"><b>{{company.name}}</b><br>{{company.phone}}</td></tr></table>
  <h2>PAYMENT RECEIPT</h2>
  <p>Receipt for invoice <b>{{invoice.number}}</b> dated {{invoice.date}}</p>
  <p>Received from <b>{{customer.name}}</b></p>
  <table class="box"><tr><td>Invoice total</td><td class="r">{{totals.grand}}</td></tr><tr><td>Received</td><td class="r"><b>{{totals.paid}}</b></td></tr><tr><td>Balance</td><td class="r">{{totals.balance}}</td></tr></table>
  <p class="foot">{{footer_note}}</p>
</div>
HTML,
                'css' => <<<'CSS'
@page { size: A5; margin: 10mm; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; color: #1b1b1b; }
table { width: 100%; border-collapse: collapse; }
.r { text-align: right; }
.logo { max-height: 14mm; }
h2 { text-align: center; letter-spacing: 2px; margin: 8mm 0; }
.box td { padding: 3mm; border: 1px solid #999; }
.foot { text-align: center; margin-top: 10mm; color: #555; }
CSS,
            ],
        ];
    }
}
