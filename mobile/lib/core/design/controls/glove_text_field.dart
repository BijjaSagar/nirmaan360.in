import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../tokens.dart';
import '../typography.dart';

/// A text field with its label above, not hidden inside as a placeholder that
/// vanishes the moment typing starts. The input is a full [N360Size.tap] tall.
class GloveTextField extends StatelessWidget {
  const GloveTextField({
    super.key,
    required this.label,
    this.controller,
    this.focusNode,
    this.hint,
    this.helper,
    this.errorText,
    this.keyboardType,
    this.textInputAction,
    this.onChanged,
    this.onSubmitted,
    this.minLines = 1,
    this.maxLines = 1,
    this.obscureText = false,
    this.enabled = true,
    this.inputFormatters,
    this.textCapitalization = TextCapitalization.sentences,
    this.prefixIcon,
    this.suffix,
    this.autofillHints,
  });

  final String label;
  final TextEditingController? controller;
  final FocusNode? focusNode;
  final String? hint;
  final String? helper;
  final String? errorText;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final ValueChanged<String>? onChanged;
  final ValueChanged<String>? onSubmitted;
  final int minLines;
  final int? maxLines;
  final bool obscureText;
  final bool enabled;
  final List<TextInputFormatter>? inputFormatters;
  final TextCapitalization textCapitalization;
  final IconData? prefixIcon;
  final Widget? suffix;
  final Iterable<String>? autofillHints;

  OutlineInputBorder _border(Color color, double width) => OutlineInputBorder(
        borderRadius: N360Radius.md,
        borderSide: BorderSide(color: color, width: width),
      );

  @override
  Widget build(BuildContext context) {
    final String? error = errorText;
    return Semantics(
      container: true,
      label: label,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          ExcludeSemantics(child: Text(label, style: N360Type.fit(N360Type.label, label))),
          const SizedBox(height: N360Space.xs),
          ConstrainedBox(
            constraints: const BoxConstraints(minHeight: N360Size.tap),
            child: TextField(
              controller: controller,
              focusNode: focusNode,
              keyboardType: keyboardType,
              textInputAction: textInputAction,
              onChanged: onChanged,
              onSubmitted: onSubmitted,
              minLines: minLines,
              maxLines: maxLines,
              obscureText: obscureText,
              enabled: enabled,
              inputFormatters: inputFormatters,
              textCapitalization: textCapitalization,
              autofillHints: autofillHints,
              style: N360Type.bodyLarge,
              cursorColor: N360Colors.laterite,
              cursorWidth: 2,
              decoration: InputDecoration(
                hintText: hint,
                hintStyle: hint == null ? null : N360Type.fit(N360Type.bodyLarge, hint!).copyWith(color: N360Colors.inkLabel),
                isDense: false,
                filled: true,
                fillColor: enabled ? N360Colors.sheet : N360Colors.paper2,
                prefixIcon: prefixIcon == null ? null : Icon(prefixIcon, size: N360IconSize.lg, color: N360Colors.ink2),
                suffixIcon: suffix,
                contentPadding: const EdgeInsets.symmetric(horizontal: N360Space.card, vertical: N360Space.card),
                border: _border(N360Colors.ruleStrong, 1),
                enabledBorder: _border(N360Colors.ruleStrong, 1),
                focusedBorder: _border(N360Colors.laterite, 2),
                errorBorder: _border(N360Colors.stampRed, 1.5),
                focusedErrorBorder: _border(N360Colors.stampRed, 2),
                disabledBorder: _border(N360Colors.rule, 1),
                errorText: null,
              ),
            ),
          ),
          if (error != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                const Padding(
                  padding: EdgeInsets.only(top: 1, right: 5),
                  child: Icon(Icons.error_outline, size: N360IconSize.sm, color: N360Colors.stampRed),
                ),
                Expanded(
                  child: Text(
                    error,
                    style: N360Type.fit(N360Type.caption, error).copyWith(color: N360Colors.stampRed, fontWeight: FontWeight.w600),
                  ),
                ),
              ],
            ),
          ] else if (helper != null) ...<Widget>[
            const SizedBox(height: N360Space.xs),
            Text(helper!, style: N360Type.fit(N360Type.caption, helper!)),
          ],
        ],
      ),
    );
  }
}
