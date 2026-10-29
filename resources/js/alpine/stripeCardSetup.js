export function registerStripeCardSetup() {
    const register = () => {
        if (!window.Alpine || typeof window.Alpine.data !== 'function') {
            return;
        }

        window.Alpine.data('stripeCardSetup', (config = {}) => ({
            stripe: null,
            elements: null,
            cardElement: null,
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

            mountCard() {
                if (this.isMounted) {
                    return;
                }

                if (!this.$refs.cardElement) {
                    setTimeout(() => {
                        if (!this.isMounted && this.$refs.cardElement) {
                            this.mountCard();
                        }
                    }, 150);
                    return;
                }

                this.stripe = Stripe(this.stripeKey);
                this.elements = this.stripe.elements();
                this.cardElement = this.elements.create('card', {
                    hidePostalCode: true,
                    style: {
                        base: {
                            fontSize: '15px',
                            color: '#18181b',
                            '::placeholder': { color: '#a1a1aa' },
                        },
                    },
                });
                this.cardElement.mount(this.$refs.cardElement);
                this.isMounted = true;
            },

            async handleSubmit() {
                if (this.isProcessing) {
                    return;
                }

                this.isProcessing = true;
                this.errorMessage = '';

                try {
                    const { setupIntent, error } = await this.stripe.confirmCardSetup(
                        this.clientSecret,
                        {
                            payment_method: { card: this.cardElement },
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
