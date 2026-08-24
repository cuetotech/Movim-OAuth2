class MovimVisioAudioWorklet extends AudioWorkletProcessor {
    static BUFFER_SIZE = 128 * 64;

    constructor() {
        super();
        this.buffer = new Float32Array(MovimVisioAudioWorklet.BUFFER_SIZE);
        this.offset = 0;
        this.maxLevel = 0;
    }

    process(inputList, outputList) {
        const input = inputList[0][0];

        if (input == undefined) {
            return true;
        }

        const output = outputList[0];
        if (output) {
            for (let channel = 0; channel < output.length; channel++) {
                output[channel].fill(0);
            }
        }

        for (let i = 0; i < input.length; i++) {
            this.buffer[i + this.offset] = input[i];
        }

        this.offset += input.length;

        if (this.offset >= this.buffer.length - 1) {
            this.flush();
        }

        return true;
    }

    flush() {
        this.offset = 0;

        let sum = 0.0;

        for (let i = 0; i < this.buffer.length; ++i) {
            sum += this.buffer[i] * this.buffer[i];
        }

        const instant = Math.sqrt(sum / this.buffer.length);
        this.maxLevel = Math.max(this.maxLevel, instant);

        if (this.maxLevel <= 0.005) {
            this.maxLevel = 0.005;
        }

        const base = instant / this.maxLevel;
        const level = base > 0.05 ? base ** 0.3 : 0;

        this.port.postMessage({
            level: level
        });
    }
}

registerProcessor('visio-audioworklet', MovimVisioAudioWorklet);
