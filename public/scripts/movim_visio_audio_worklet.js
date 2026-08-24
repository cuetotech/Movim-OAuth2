class MovimVisioAudioWorklet extends AudioWorkletProcessor {
    static BUFFER_SIZE = 128 * 64;

    constructor() {
        super();
        this.buffer = new Float32Array(MovimVisioAudioWorklet.BUFFER_SIZE);
        this.offset = 0;
        this.maxLevel = 0;
    }

    process(inputList, outputList) {
        const input = inputList[0]?.[0];
        if (!input) return true;

        const output = outputList[0];
        if (output) {
            for (const channel of output) channel.fill(0);
        }

        const remaining = this.buffer.length - this.offset;
        const count = Math.min(input.length, remaining);
        this.buffer.set(input.subarray(0, count), this.offset);
        this.offset += count;

        if (this.offset >= this.buffer.length) this.flush();
        return true;
    }

    flush() {
        let sum = 0;
        for (let i = 0; i < this.offset; i++) sum += this.buffer[i] * this.buffer[i];

        const instant = this.offset > 0 ? Math.sqrt(sum / this.offset) : 0;
        this.maxLevel = Math.max(this.maxLevel, instant, 0.005);
        const base = instant / this.maxLevel;
        const level = base > 0.05 ? base ** 0.3 : 0;

        this.port.postMessage({ level });
        this.offset = 0;
    }
}

registerProcessor('visio-audioworklet', MovimVisioAudioWorklet);
