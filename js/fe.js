/**
 * Wayforpay Gateway Frontend Component for GiveWP v3 Visual Form Builder.
 *
 * @see https://developer.mozilla.org/en-US/docs/Glossary/IIFE
 */
(() => {
    let settings = {};

    /**
     * Renders the Wayforpay gateway option selection in the UI.
     *
     * @see https://react.dev/reference/react/createElement
     */
    function WayforpayGatewayFields() {
        return window.wp.element.createElement(
            "div",
            {
                className: 'wayforpay-gateway-help-text'
            },
            settings.iconUrl && window.wp.element.createElement(
                "img",
                {
                    src: settings.iconUrl,
                    alt: 'Wayforpay',
                    style: {
                        maxWidth: '160px',
                        height: 'auto'
                    }
                }
            ),
            window.wp.element.createElement(
                "p",
                {
                    style: { marginBottom: 0 }
                },
                settings.message
            )
        );
    }

    const WayforpayGateway = {
        id: "wayforpay-gateway",
        initialize() {
            settings = this.settings;
        },
        Fields() {
            return window.wp.element.createElement(WayforpayGatewayFields);
        },
    };

    window.givewp.gateways.register(WayforpayGateway);
})();