---
name: Batch Tracer
description: Operational workspace for traceability and Recall
colors:
  primary: "#6b4eff"
  navigation: "#302650"
  background: "#f8f8fc"
  surface: "#ffffff"
  foreground: "#302747"
  secondary-text: "#706982"
  border: "#e5e1ee"
  attention: "#9b4c0d"
  danger: "#9a302b"
typography:
  heading:
    fontFamily: "Segoe UI, sans-serif"
    fontSize: "26px"
    fontWeight: 650
    lineHeight: 1.25
    letterSpacing: "-0.025em"
  body:
    fontFamily: "Segoe UI, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.65
rounded:
  control: "6px"
  panel: "11px"
spacing:
  small: "8px"
  medium: "16px"
  section: "28px"
  page: "30px"
---

## Overview
Light operational workspace for reading batch and Recall records. The user approved a purple analytics dashboard reference: dark purple navigation, a light canvas, pastel summary panels, charts and compact tables. The PRD governs product behavior; the supplied visual reference governs the updated composition.

## Colors
Dark purple anchors navigation. Violet identifies primary actions, links and selected chart bars. Lavender, mint and peach separate the three summary measures. Amber identifies nonzero unresolved quantities; red identifies blocking states. Text labels accompany every status color.

## Typography
The platform sans face serves operational reading. Tables and quantity summaries use tabular numerals. Page titles lead; section headings are smaller and separated from the preceding content. System typography is an operational choice, not a decorative brand display.

## Layout
Persistent navigation (224px) on desktop; collapsible navigation below 1000px. The dashboard uses a wide analytical column and a narrower supporting column. Below 760px panels stack; below 440px metrics become compact horizontal rows. Tables preserve readable column widths and scroll within their own region. The top-bar search opens filtered batch results.

## Elevation & Depth
White panels use thin borders with a restrained offset shadow (0 3px 15px rgba(48,38,80,.035)). Dialog overlays provide protected focus for recorded transitions and detailed audit comparisons.

## Shapes
Controls use gently rounded corners; record panels use a slightly larger radius. Status badges have compact rounded outlines.

## Components
shadcn/ui provides buttons, inputs, labels, tables, badges, dialogs, tabs and loading skeletons. Audit comparisons use a labelled Field / Before / After table inside a read-only dialog. Timelines show subject, actor, time, change summary and expandable fields. IDs resolve to codes/names and relevant record links. Charts expose the underlying data in accessible tables; the shipment graph supports pointer and arrow-key inspection. Critical actions show a confirmation summary. All labels support ID/EN.

## Do's and Don'ts
Do preserve text labels for statuses, visible keyboard focus, explicit quantity definitions and pending states. Do keep tables readable through local scrolling. Don't render raw event JSON as the user interface, fabricate chart trends, or treat omitted fields in sparse audit patches as deleted values. Don't communicate recovery through contact confirmation alone.
