import SwiftUI
import UIKit

/// Init function named in this plugin's manifest under `ios.init_function`.
/// The generated `PluginBridgeFunctionRegistration` calls it at app start,
/// before the first tree render. Nothing draws until PHP selects the
/// `devnull:side-rail` presentation and the window is wide enough.
func registerDevnullSideRail() {
    NativeTabPresentationRegistry.shared.register(
        identifier: devnullSideRailIdentifier,
        presenter: { context in AnyView(DevnullSideRail(context: context)) },
        overlay: { context in AnyView(DevnullNewCommitButton(context: context)) }
    )
}

let devnullSideRailIdentifier = "devnull:side-rail"

// MARK: - Pure helpers

/// The new-commit tab is drawn as the round button, never in the column.
func devnullSideRailIsCommitTab(_ tab: NativeTabDescriptor) -> Bool {
    tab.id.hasPrefix("commit#")
}

func devnullSideRailColumnTabs(_ tabs: [NativeTabDescriptor]) -> [NativeTabDescriptor] {
    tabs.filter { !devnullSideRailIsCommitTab($0) }
}

func devnullSideRailCommitTab(_ tabs: [NativeTabDescriptor]) -> NativeTabDescriptor? {
    tabs.first(where: devnullSideRailIsCommitTab)
}

/// The count shown next to an expanded item, or nil when there is none.
func devnullSideRailBadgeText(_ tab: NativeTabDescriptor) -> String? {
    guard let badge = tab.badge, !badge.isEmpty else { return nil }
    return badge
}

/// Whether a collapsed item shows its dot: a count, or news without one.
func devnullSideRailShowsDot(_ tab: NativeTabDescriptor) -> Bool {
    devnullSideRailBadgeText(tab) != nil || tab.showsNews
}

/// Converts a CSS hex colour (`#RGB`, `#RGBA`, `#RRGGBB`, `#RRGGBBAA`) to the
/// ARGB integer core's `colorFromARGB` takes. Nil for anything else.
func devnullSideRailARGB(fromCSSHex value: String?) -> Int? {
    guard var hex = value?.trimmingCharacters(in: .whitespaces), hex.hasPrefix("#") else {
        return nil
    }

    hex.removeFirst()

    if hex.count == 3 || hex.count == 4 {
        hex = String(hex.flatMap { [$0, $0] })
    }

    guard hex.count == 6 || hex.count == 8,
          hex.allSatisfy(\.isHexDigit),
          let raw = UInt32(hex, radix: 16) else {
        return nil
    }

    let rgba = hex.count == 6 ? (raw << 8) | 0xFF : raw

    return Int((rgba >> 8) | ((rgba & 0xFF) << 24))
}

func devnullSideRailAccent(_ configuration: NativeTabPresentationConfiguration) -> Color {
    guard let argb = devnullSideRailARGB(fromCSSHex: configuration.string("accent")) else {
        return .accentColor
    }

    return colorFromARGB(argb)
}

// MARK: - Column

struct DevnullSideRail: View {
    let context: NativeTabPresentationContext

    @AppStorage("devnull.sideRail.expanded") private var isExpanded = false
    @State private var windowControlsInset: CGFloat = 0
    @ObservedObject private var theme = NativeUITheme.shared
    @Environment(\.colorScheme) private var colorScheme

    private static let collapsedWidth: CGFloat = 56
    private static let expandedWidth: CGFloat = 190

    private var accent: Color { devnullSideRailAccent(context.configuration) }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            wordmark
                .padding(.leading, 6)
                .padding(.bottom, 14)

            ForEach(devnullSideRailColumnTabs(context.tabs)) { tab in
                DevnullSideRailItem(
                    tab: tab,
                    isSelected: tab.id == context.selectedTabIdentifier,
                    isExpanded: isExpanded,
                    accent: accent
                ) {
                    context.activate(tab.id)
                }
            }

            Spacer(minLength: 0)

            toggle
        }
        .padding(.top, windowControlsInset)
        .padding(.vertical, 18)
        .padding(.horizontal, 8)
        .frame(width: isExpanded ? Self.expandedWidth : Self.collapsedWidth, alignment: .leading)
        .frame(maxHeight: .infinity, alignment: .top)
        .background(DevnullWindowControlsInset(inset: $windowControlsInset))
        .clipped()
        .background(theme.resolve(for: colorScheme).background.ignoresSafeArea())
        .overlay(alignment: .trailing) {
            Rectangle()
                .fill(Color(uiColor: .separator))
                .frame(width: 1)
                .ignoresSafeArea()
        }
    }

    private var wordmark: some View {
        let mark = context.configuration.string("wordmark") ?? "/dev/null"

        return Text(wordmarkText(isExpanded ? mark : nil))
            .font(.system(size: 18, weight: .heavy))
            .lineLimit(1)
            .fixedSize()
            .accessibilityLabel(mark)
            .accessibilityAddTraits(.isHeader)
            .accessibilityHidden(!isExpanded)
    }

    /// The `//` mark in accent, then the wordmark with its inner slashes in
    /// accent and the leading one left plain, as in the approved mockup.
    private func wordmarkText(_ mark: String?) -> AttributedString {
        var slashes = AttributedString("//")
        slashes.foregroundColor = accent

        guard let mark else {
            return slashes
        }

        var attributed = slashes + AttributedString(" ")

        for (offset, character) in mark.enumerated() {
            var run = AttributedString(String(character))

            if character == "/", offset > 0 {
                run.foregroundColor = accent
            }

            attributed.append(run)
        }

        return attributed
    }

    private var toggle: some View {
        let label = isExpanded
            ? context.configuration.string("collapseLabel") ?? "Collapse"
            : context.configuration.string("expandLabel") ?? "Expand"

        return Button {
            withAnimation(.snappy(duration: 0.25)) {
                isExpanded.toggle()
            }
        } label: {
            HStack(spacing: 12) {
                Image(systemName: isExpanded ? "chevron.left.2" : "chevron.right.2")
                    .font(.system(size: 15, weight: .semibold))
                    .frame(width: 22, height: 22)

                if isExpanded {
                    Text(label)
                        .font(.subheadline)
                        .lineLimit(1)
                }
            }
            .padding(.horizontal, 9)
            .padding(.vertical, 11)
            .frame(maxWidth: isExpanded ? .infinity : nil, alignment: .leading)
            .foregroundStyle(.secondary)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }
}

private struct DevnullSideRailItem: View {
    let tab: NativeTabDescriptor
    let isSelected: Bool
    let isExpanded: Bool
    let accent: Color
    let activate: () -> Void

    private static let badgeColor = Color(uiColor: .systemRed)

    var body: some View {
        Button(action: activate) {
            HStack(spacing: 12) {
                NativeTabIcon(tab.icon, pointSize: 18)
                    .frame(width: 22, height: 22)
                    .overlay(alignment: .topTrailing) {
                        if !isExpanded, devnullSideRailShowsDot(tab) {
                            Circle()
                                .fill(Self.badgeColor)
                                .frame(width: 8, height: 8)
                                .offset(x: 3, y: -3)
                        }
                    }

                if isExpanded {
                    Text(tab.label)
                        .font(.subheadline.weight(isSelected ? .semibold : .regular))
                        .lineLimit(1)

                    Spacer(minLength: 0)

                    expandedBadge
                }
            }
            .padding(.horizontal, 9)
            .padding(.vertical, 11)
            .frame(maxWidth: isExpanded ? .infinity : nil, alignment: .leading)
            .foregroundStyle(isSelected ? accent : Color.secondary)
            .background(
                RoundedRectangle(cornerRadius: 10, style: .continuous)
                    .fill(isSelected ? accent.opacity(0.14) : .clear)
            )
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(tab.accessibilityLabel)
        .accessibilityValue(devnullSideRailBadgeText(tab) ?? "")
        .accessibilityAddTraits(isSelected ? .isSelected : [])
    }

    @ViewBuilder
    private var expandedBadge: some View {
        if let badge = devnullSideRailBadgeText(tab) {
            Text(badge)
                .font(.caption2.weight(.semibold))
                .foregroundStyle(.white)
                .padding(.horizontal, 6)
                .padding(.vertical, 1)
                .background(Capsule().fill(Self.badgeColor))
        } else if tab.showsNews {
            Circle()
                .fill(Self.badgeColor)
                .frame(width: 8, height: 8)
        }
    }
}

// MARK: - Window controls

/// How far a windowed iPad's window controls (close, minimise and resize, at
/// the window's top-leading corner) reach below the top safe area, so the
/// column's first item sits under them rather than beneath them. iPadOS 26
/// reports it as the safe area adapted to the window's corner. Zero full
/// screen, on a phone, and before iPadOS 26.
private struct DevnullWindowControlsInset: UIViewRepresentable {
    @Binding var inset: CGFloat

    func makeUIView(context: Context) -> Probe {
        let probe = Probe()
        probe.isUserInteractionEnabled = false
        probe.report = { inset = $0 }

        return probe
    }

    func updateUIView(_ probe: Probe, context: Context) {
        probe.report = { inset = $0 }
    }

    final class Probe: UIView {
        var report: ((CGFloat) -> Void)?
        private var reported: CGFloat?

        override func layoutSubviews() {
            super.layoutSubviews()
            measure()
        }

        override func safeAreaInsetsDidChange() {
            super.safeAreaInsetsDidChange()
            measure()
        }

        private func measure() {
            guard #available(iOS 26.0, *), window != nil else {
                return
            }

            let adapted = directionalEdgeInsets(for: .safeArea(cornerAdaptation: .vertical)).top
            let extra = max(0, (adapted - safeAreaInsets.top).rounded(.up))

            guard extra != reported else {
                return
            }

            reported = extra

            DispatchQueue.main.async { [weak self] in
                self?.report?(extra)
            }
        }
    }
}

// MARK: - Round new-commit button

struct DevnullNewCommitButton: View {
    let context: NativeTabPresentationContext

    var body: some View {
        if let tab = devnullSideRailCommitTab(context.tabs), !context.isPushed {
            let accent = devnullSideRailAccent(context.configuration)

            Button {
                context.activate(tab.id)
            } label: {
                Image(systemName: "plus")
                    .font(.system(size: 26, weight: .semibold))
                    .foregroundStyle(.white)
                    .frame(width: 58, height: 58)
                    .background(Circle().fill(accent))
                    .shadow(color: accent.opacity(0.4), radius: 8, y: 6)
                    .contentShape(Circle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(tab.label)
            .padding(24)
        }
    }
}
