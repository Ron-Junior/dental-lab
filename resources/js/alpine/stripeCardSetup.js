export function registerStripeCardSetup() {
    const register = () => {
        if (!window.Alpine || typeof window.Alpine.data !== 'function') {
            return;
        }

        window.Alpine.data('stripeCardSetup', (config = {}) => ({
            stripe: null,
            elements: null,
            cardElement: null,
            cardNumberElement: null,
            cardExpiryElement: null,
            cardCvcElement: null,
            cardholderName: '',
            clientSecret: config.clientSecret || '',
            stripeKey: config.stripeKey || '',
            wireAction: config.wireAction || 'confirmPaymentMethod',
            errorMessage: '',
            isProcessing: false,
            isMounted: false,

            init() {
                if (!this.stripeKey) {
                    return;
                }
                this.loadStripe();
            },

            loadStripe() {
                if (window.Stripe) {
                    this.mountCard();
                } else {
                    const script = document.createElement('script');
                    script.src = 'https://js.stripe.com/v3/';
                    script.onload = () => this.mountCard();
                    document.head.appendChild(script);
                }
            },

            isDarkMode() {
                return (
                    document.documentElement.classList.contains('dark') ||
                    (!document.documentElement.classList.contains('light') &&
                        window.matchMedia &&
                        window.matchMedia('(prefers-color-scheme: dark)').matches)
                );
            },

            getCardStyle(isDark) {
                return {
                    base: {
                        fontSize: '15px',
                        color: isDark ? '#f4f4f5' : '#18181b',
                        fontFamily: 'Figtree, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
                        '::placeholder': {
                            color: isDark ? '#71717a' : '#a1a1aa',
                        },
                        iconColor: isDark ? '#a1a1aa' : '#71717a',
                    },
                    invalid: {
                        color: '#f43f5e',
                        iconColor: '#f43f5e',
                    },
                };
            },

            updateCardTheme() {
                const style = this.getCardStyle(this.isDarkMode());
                if (this.cardNumberElement) {
                    this.cardNumberElement.update({ style });
                }
                if (this.cardExpiryElement) {
                    this.cardExpiryElement.update({ style });
                }
                if (this.cardCvcElement) {
                    this.cardCvcElement.update({ style });
                }
                if (this.cardElement) {
                    this.cardElement.update({ style });
                }
            },

            mountCard() {
                if (this.isMounted) {
                    return;
                }

                const hasSplitInputs = this.$refs.cardNumber && this.$refs.cardExpiry && this.$refs.cardCvc;
                const hasSingleInput = this.$refs.cardElement;

                if (!hasSplitInputs && !hasSingleInput) {
                    setTimeout(() => {
                        if (!this.isMounted && (this.$refs.cardNumber || this.$refs.cardElement)) {
                            this.mountCard();
                        }
                    }, 150);
                    return;
                }

                const isDark = this.isDarkMode();
                this.stripe = Stripe(this.stripeKey);
                this.elements = this.stripe.elements();

                const handleElementChange = (event) => {
                    if (event.error) {
                        this.errorMessage = event.error.message;
                    } else {
                        this.errorMessage = '';
                    }
                };

                if (hasSplitInputs) {
                    this.cardNumberElement = this.elements.create('cardNumber', {
                        style: this.getCardStyle(isDark),
                        showIcon: true,
                    });
                    this.cardExpiryElement = this.elements.create('cardExpiry', {
                        style: this.getCardStyle(isDark),
                    });
                    this.cardCvcElement = this.elements.create('cardCvc', {
                        style: this.getCardStyle(isDark),
                    });

                    this.cardNumberElement.on('change', handleElementChange);
                    this.cardExpiryElement.on('change', handleElementChange);
                    this.cardCvcElement.on('change', handleElementChange);

                    this.cardNumberElement.mount(this.$refs.cardNumber);
                    this.cardExpiryElement.mount(this.$refs.cardExpiry);
                    this.cardCvcElement.mount(this.$refs.cardCvc);
                } else if (hasSingleInput) {
                    this.cardElement = this.elements.create('card', {
                        hidePostalCode: true,
                        style: this.getCardStyle(isDark),
                    });
                    this.cardElement.on('change', handleElementChange);
                    this.cardElement.mount(this.$refs.cardElement);
                }

                this.isMounted = true;

                if (window.MutationObserver) {
                    const observer = new MutationObserver(() => {
                        this.updateCardTheme();
                    });
                    observer.observe(document.documentElement, {
                        attributes: true,
                        attributeFilter: ['class'],
                    });
                }

                if (window.matchMedia) {
                    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                        this.updateCardTheme();
                    });
                }
            },

            async handleSubmit() {
                if (this.isProcessing) {
                    return;
                }

                const targetCard = this.cardNumberElement || this.cardElement;
                if (!targetCard) {
                    this.errorMessage = 'Formulário de cartão não carregado. Recarregue a página.';
                    return;
                }

                this.isProcessing = true;
                this.errorMessage = '';

                const paymentMethodData = {
                    card: targetCard,
                };

                if (this.cardholderName && this.cardholderName.trim()) {
                    paymentMethodData.billing_details = {
                        name: this.cardholderName.trim(),
                    };
                }

                try {
                    const { setupIntent, error } = await this.stripe.confirmCardSetup(
                        this.clientSecret,
                        {
                            payment_method: paymentMethodData,
                        }
                    );

                    if (error) {
                        this.errorMessage = error.message;
                        this.isProcessing = false;
                    } else if (this.$wire && typeof this.$wire[this.wireAction] === 'function') {
                        await this.$wire[this.wireAction](setupIntent.payment_method);
                        this.isProcessing = false;
                    }
                } catch (err) {
                    this.errorMessage = 'Erro ao processar o cartão. Tente novamente.';
                    this.isProcessing = false;
                }
            },
        }));
    };

    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
}
